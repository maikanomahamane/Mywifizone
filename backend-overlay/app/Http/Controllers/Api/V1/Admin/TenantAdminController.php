<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\SubscriptionService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Super Admin : liste des tenants, suspension et réactivation (les deux sont idempotentes et auditées). */
class TenantAdminController extends Controller
{
    public function __construct(private readonly SubscriptionService $subscriptions) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in([Tenant::STATUS_ACTIVE, Tenant::STATUS_SUSPENDED])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = Tenant::with('subscription.plan')
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->orderByDesc('created_at')
            ->paginate($filters['per_page'] ?? 25);

        return ApiResponse::ok(
            collect($page->items())->map(fn (Tenant $t) => $this->payload($t))->all(),
            ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        );
    }

    public function suspend(Request $request, string $id): JsonResponse
    {
        $tenant = Tenant::findOrFail($id);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        $this->subscriptions->suspend($tenant, $data['reason'] ?? null);

        return ApiResponse::ok($this->payload($tenant->fresh('subscription.plan')));
    }

    public function reactivate(string $id): JsonResponse
    {
        $tenant = Tenant::findOrFail($id);

        $this->subscriptions->reactivate($tenant);

        return ApiResponse::ok($this->payload($tenant->fresh('subscription.plan')));
    }

    /** @return array<string, mixed> */
    private function payload(Tenant $tenant): array
    {
        return [
            'id' => $tenant->getKey(),
            'name' => $tenant->name,
            'country' => $tenant->country,
            'status' => $tenant->status,
            'created_at' => $tenant->created_at?->toIso8601String(),
            'subscription' => $tenant->subscription?->toPayload(),
        ];
    }
}
