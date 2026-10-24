<?php

namespace App\Http\Middleware;

use App\Domain\Tenancy\TenantContext;
use App\Models\Router;
use App\Models\Tenant;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authentifie l'agent d'un routeur par son jeton (en-tête Authorization: Bearer wza_...). Le routeur — donc le tenant —
 * est déduit du jeton, jamais d'un paramètre : un agent ne voit et ne touche que SON routeur.
 */
class AuthenticateAgent
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) $request->bearerToken();

        if (! str_starts_with($token, 'wza_') || strlen($token) > 80) {
            return ApiResponse::error('unauthenticated', 'Jeton d\'agent invalide.', 401);
        }

        // Le jeton a 48 caractères aléatoires : la recherche par hachage unique suffit (pas de comparaison à temps constant utile).
        $router = Router::withoutGlobalScopes()->where('agent_token_hash', hash('sha256', $token))->first();

        if (! $router) {
            return ApiResponse::error('unauthenticated', 'Jeton d\'agent invalide.', 401);
        }

        $tenant = Tenant::find($router->tenant_id);
        if (! $tenant || $tenant->isSuspended()) {
            return ApiResponse::error('tenant_suspended', 'Compte suspendu.', 403);
        }

        $request->attributes->set('router', $router);
        $this->context->set($tenant->getKey());
        Log::withContext(['tenant_id' => $tenant->getKey(), 'router_id' => $router->getKey()]);

        try {
            return $next($request);
        } finally {
            $this->context->clear();
        }
    }
}
