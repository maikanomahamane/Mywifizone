<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Super Admin : journal d'audit (append-only, lecture seule), filtrable par action ou tenant. */
class AuditLogAdminController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'action' => ['nullable', 'string', 'max:64'],
            'tenant_id' => ['nullable', 'uuid'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = AuditLog::query()
            ->when($filters['action'] ?? null, fn ($query, $v) => $query->where('action', $v))
            ->when($filters['tenant_id'] ?? null, fn ($query, $v) => $query->where('tenant_id', $v))
            ->orderByDesc('created_at')
            ->paginate($filters['per_page'] ?? 50);

        return ApiResponse::ok(
            collect($page->items())->map(fn (AuditLog $l) => [
                'id' => $l->getKey(),
                'tenant_id' => $l->tenant_id,
                'user_id' => $l->user_id,
                'action' => $l->action,
                'subject_type' => $l->subject_type,
                'subject_id' => $l->subject_id,
                'ip' => $l->ip,
                'request_id' => $l->request_id,
                'metadata' => $l->metadata,
                'created_at' => $l->created_at?->toIso8601String(),
            ])->all(),
            ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        );
    }
}
