<?php

namespace App\Http\Middleware;

use App\Domain\Tenancy\TenantContext;
use App\Models\Tenant;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * À placer APRÈS auth:sanctum. Le tenant vient exclusivement de l'utilisateur authentifié,
 * jamais d'un en-tête ou d'un paramètre fourni par le client.
 */
class SetTenantContext
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->isActive()) {
            return ApiResponse::error('account_disabled', 'Compte désactivé.', 403);
        }

        if ($user->tenant_id === null) {
            return ApiResponse::error('tenant_required', 'Cette route nécessite un tenant.', 403);
        }

        // Lecture fraîche à chaque requête : un tenant suspendu est bloqué immédiatement.
        $tenant = Tenant::find($user->tenant_id);

        if (! $tenant || $tenant->isSuspended()) {
            return ApiResponse::error('tenant_suspended', 'Compte suspendu.', 403);
        }

        $this->context->set($tenant->getKey());
        Log::withContext(['tenant_id' => $tenant->getKey(), 'user_id' => $user->getKey()]);

        try {
            return $next($request);
        } finally {
            $this->context->clear();
        }
    }
}
