<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Network\VoucherStatus;
use App\Http\Controllers\Controller;
use App\Models\Voucher;
use App\Services\VoucherService;
use App\Support\ApiResponse;
use App\Support\Rules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class VoucherController extends Controller
{
    public function __construct(private readonly VoucherService $vouchers) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Voucher::class);

        $f = $request->validate([
            'status' => ['nullable', Rule::in(VoucherStatus::ALL)],
            'sync_status' => ['nullable', Rule::in([Voucher::SYNC_PENDING, Voucher::SYNC_SYNCED, Voucher::SYNC_FAILED])],
            'router_id' => ['nullable', 'uuid'],
            'site_id' => ['nullable', 'uuid'],
            'batch_id' => ['nullable', 'uuid'],
            'profile_id' => ['nullable', 'uuid'],
            'is_vip' => ['nullable', 'boolean'],
            'q' => ['nullable', 'string', 'max:12'],
        ]);

        $page = Voucher::query()
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['sync_status'] ?? null, fn ($q, $v) => $q->where('sync_status', $v))
            ->when($f['router_id'] ?? null, fn ($q, $v) => $q->where('router_id', $v))
            ->when($f['site_id'] ?? null, fn ($q, $v) => $q->where('site_id', $v))
            ->when($f['batch_id'] ?? null, fn ($q, $v) => $q->where('batch_id', $v))
            ->when($f['profile_id'] ?? null, fn ($q, $v) => $q->where('profile_id', $v))
            ->when(array_key_exists('is_vip', $f) && $f['is_vip'] !== null, fn ($q) => $q->where('is_vip', $request->boolean('is_vip')))
            ->when($f['q'] ?? null, fn ($q, $v) => $q->where('code', 'like', strtoupper(addcslashes($v, '%_\\')).'%'))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(Rules::perPage($request));

        return ApiResponse::paginated($page, fn (Voucher $v) => $v->toPayload());
    }

    public function show(string $id): JsonResponse
    {
        $voucher = Voucher::findOrFail($id);
        Gate::authorize('view', $voucher);

        return ApiResponse::ok($voucher->toPayload());
    }

    public function revoke(Request $request, string $id): JsonResponse
    {
        $voucher = Voucher::findOrFail($id);
        Gate::authorize('revoke', $voucher);

        $this->vouchers->revoke($voucher, $request->user());

        return ApiResponse::ok($voucher->refresh()->toPayload());
    }
}
