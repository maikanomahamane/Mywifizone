<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Network\ProfileKind;
use App\Domain\Network\RouterOsValue;
use App\Http\Controllers\Controller;
use App\Models\HotspotProfile;
use App\Models\Router;
use App\Models\Voucher;
use App\Models\VoucherBatch;
use App\Services\PlanEntitlements;
use App\Services\RouterService;
use App\Services\VoucherService;
use App\Support\ApiException;
use App\Support\ApiResponse;
use App\Support\Rules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

class VoucherBatchController extends Controller
{
    public function __construct(
        private readonly VoucherService $vouchers,
        private readonly RouterService $routers,
        private readonly PlanEntitlements $entitlements,
    ) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', VoucherBatch::class);

        $page = VoucherBatch::query()->orderByDesc('created_at')->orderByDesc('id')->paginate(Rules::perPage($request));

        return ApiResponse::paginated($page, fn (VoucherBatch $b) => $b->toPayload());
    }

    public function show(string $id): JsonResponse
    {
        $batch = VoucherBatch::findOrFail($id);
        Gate::authorize('view', $batch);

        $counts = Voucher::where('batch_id', $batch->getKey())
            ->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')
            ->map(fn ($n) => (int) $n)->all();

        return ApiResponse::ok($batch->toPayload($counts));
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', VoucherBatch::class);

        $max = $this->entitlements->limitOr('max_batch_size', (int) config('network.default_max_batch_size'));
        $data = $request->validate([
            'router_id' => ['required', 'uuid', Rules::ownedBy(Router::class)],
            'profile_id' => ['required', 'uuid', Rules::ownedBy(HotspotProfile::class)],
            'quantity' => ['required', 'integer', 'min:1', $max === null ? 'max:100000' : 'max:'.$max],
            'label' => ['nullable', 'string', 'max:80'],
            'valid_until' => ['nullable', 'date', 'after:now'],
            'code_length' => ['sometimes', 'integer', 'min:'.RouterOsValue::CODE_MIN, 'max:'.RouterOsValue::CODE_MAX],
        ]);

        $router = Router::findOrFail($data['router_id']);
        $profile = HotspotProfile::findOrFail($data['profile_id']);

        if ($profile->kind === ProfileKind::VIP) {
            $this->entitlements->assertFeature('vip');
        }
        $this->routers->assertEnrolled($router);

        $options = [
            'label' => $data['label'] ?? null,
            'valid_until' => isset($data['valid_until']) ? Carbon::parse($data['valid_until']) : null,
            'code_length' => $data['code_length'] ?? 8,
        ];

        $batch = $this->vouchers->createBatch($router, $profile, (int) $data['quantity'], $options, $request->user());

        // 202 : les vouchers deviennent utilisables quand le routeur a confirmé leur création.
        return ApiResponse::ok($batch->toPayload(['GENERATED' => $batch->quantity]), status: 202);
    }
}
