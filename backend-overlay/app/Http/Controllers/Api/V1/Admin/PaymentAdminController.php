<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\PaymentTransaction;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Super Admin : paiements de tous les tenants (lecture seule). */
class PaymentAdminController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', 'string', 'max:16'],
            'provider' => ['nullable', 'string', 'max:32'],
            'tenant_id' => ['nullable', 'uuid'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        // Hors contexte tenant : le scope global ferait tout disparaître, y compris la relation `invoice`.
        $page = PaymentTransaction::withoutGlobalScopes()
            ->with(['invoice' => fn ($query) => $query->withoutGlobalScopes()])
            ->when($filters['status'] ?? null, fn ($query, $v) => $query->where('status', $v))
            ->when($filters['provider'] ?? null, fn ($query, $v) => $query->where('provider', $v))
            ->when($filters['tenant_id'] ?? null, fn ($query, $v) => $query->where('tenant_id', $v))
            ->orderByDesc('created_at')
            ->paginate($filters['per_page'] ?? 25);

        return ApiResponse::ok(
            collect($page->items())->map(fn (PaymentTransaction $t) => $t->toPayload() + [
                'tenant_id' => $t->tenant_id,
                'invoice_number' => $t->invoice?->number,
                'created_at' => $t->created_at?->toIso8601String(),
            ])->all(),
            ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        );
    }
}
