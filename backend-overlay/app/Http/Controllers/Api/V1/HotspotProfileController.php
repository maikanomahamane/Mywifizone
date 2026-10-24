<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Network\ProfileKind;
use App\Domain\Network\RouterOsValue;
use App\Http\Controllers\Controller;
use App\Models\HotspotProfile;
use App\Services\AuditLogger;
use App\Services\PlanEntitlements;
use App\Support\ApiException;
use App\Support\ApiResponse;
use App\Support\Rules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class HotspotProfileController extends Controller
{
    public function __construct(
        private readonly PlanEntitlements $entitlements,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', HotspotProfile::class);

        $page = HotspotProfile::query()->orderBy('kind')->orderBy('price')->orderBy('name')->paginate(Rules::perPage($request, 50));

        return ApiResponse::paginated($page, fn (HotspotProfile $p) => $p->toPayload());
    }

    public function show(string $id): JsonResponse
    {
        $profile = HotspotProfile::findOrFail($id);
        Gate::authorize('view', $profile);

        return ApiResponse::ok($profile->toPayload());
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', HotspotProfile::class);

        $data = $this->validated($request, creating: true);
        if (($data['kind'] ?? ProfileKind::STANDARD) === ProfileKind::VIP) {
            $this->entitlements->assertFeature('vip');
        }

        $profile = HotspotProfile::create($data);
        $this->audit->log('hotspot_profile.created', null, $profile, ['name' => $profile->name]);

        return ApiResponse::ok($profile->toPayload(), status: 201);
    }

    /** Les vouchers déjà émis gardent leurs valeurs (instantané) : modifier un profil n'affecte que les futurs lots. */
    public function update(Request $request, string $id): JsonResponse
    {
        $profile = HotspotProfile::findOrFail($id);
        Gate::authorize('update', $profile);

        $data = $this->validated($request, creating: false);
        if (($data['kind'] ?? $profile->kind) === ProfileKind::VIP) {
            $this->entitlements->assertFeature('vip');
        }

        $profile->update($data);
        $this->audit->log('hotspot_profile.updated', null, $profile);

        return ApiResponse::ok($profile->toPayload());
    }

    public function destroy(string $id): JsonResponse
    {
        $profile = HotspotProfile::findOrFail($id);
        Gate::authorize('delete', $profile);

        if ($profile->vouchers()->exists()) {
            throw new ApiException('profile_in_use', 'Ce profil a des vouchers : désactivez-le plutôt que de le supprimer.', 409);
        }

        $profile->delete();
        $this->audit->log('hotspot_profile.deleted', null, $profile, ['name' => $profile->name]);

        return ApiResponse::ok(null);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $creating): array
    {
        // Devise en majuscules avant validation (« xof » accepté).
        if (is_string($request->input('currency'))) {
            $request->merge(['currency' => strtoupper($request->input('currency'))]);
        }
        $req = $creating ? 'required' : 'sometimes';

        $data = $request->validate([
            'name' => [$req, 'string', 'max:80'],
            'kind' => ['sometimes', Rule::in(ProfileKind::ALL)],
            'price' => [$req, 'integer', 'min:0', 'max:100000000'],   // unités mineures, jamais de décimaux
            'currency' => [$req, 'string', 'size:3', Rule::exists('currencies', 'code')],
            'duration_minutes' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:525600'],
            'rate_limit' => ['sometimes', 'nullable', 'string', 'max:24', function (string $a, mixed $v, \Closure $fail) {
                if ($v !== null && ! RouterOsValue::isValidRateLimit($v)) {
                    $fail('Format de débit invalide (ex. 2M/2M ou 512k).');
                }
            }],
            'device_limit' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'data_limit_mb' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:10000000'],
            'is_active' => ['sometimes', 'boolean'],
            'portal_visible' => ['sometimes', 'boolean'],
        ]);

        return $data;
    }
}
