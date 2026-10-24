<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\Subscription;
use App\Services\AuditLogger;
use App\Services\PlanEntitlements;
use App\Support\ApiException;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class SiteController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PlanEntitlements $entitlements,
    ) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Site::class);

        $page = Site::query()->orderBy('name')->paginate(min((int) $request->integer('per_page', 25), 100));

        return ApiResponse::ok(
            collect($page->items())->map(fn (Site $s) => $this->payload($s))->all(),
            ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        );
    }

    // Chargement par id sous scope tenant : 404 si la ressource appartient à un autre tenant.
    public function show(string $id): JsonResponse
    {
        $site = Site::findOrFail($id);
        Gate::authorize('view', $site);

        return ApiResponse::ok($this->payload($site));
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', Site::class);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:255'],
        ]);

        $site = DB::transaction(function () use ($data) {
            // Verrou sur la ligne d'abonnement du tenant : deux créations simultanées ne peuvent pas dépasser la limite du plan.
            Subscription::lockForUpdate()->first();

            if (! $this->entitlements->canAdd('max_sites', Site::count())) {
                throw new ApiException('plan_limit_reached', 'Limite de sites atteinte pour votre formule.', 403, [
                    'limit' => 'max_sites',
                    'max' => $this->entitlements->limit('max_sites'),
                ]);
            }

            return Site::create($data);
        });

        $this->audit->log('site.created', null, $site);

        return ApiResponse::ok($this->payload($site), status: 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $site = Site::findOrFail($id);
        Gate::authorize('update', $site);

        $site->update($request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:255'],
        ]));

        $this->audit->log('site.updated', null, $site);

        return ApiResponse::ok($this->payload($site));
    }

    public function destroy(string $id): JsonResponse
    {
        $site = Site::findOrFail($id);
        Gate::authorize('delete', $site);

        $site->delete();
        $this->audit->log('site.deleted', null, $site);

        return ApiResponse::ok(null);
    }

    /** @return array<string, mixed> */
    private function payload(Site $site): array
    {
        return ['id' => $site->getKey(), 'name' => $site->name, 'address' => $site->address];
    }
}
