<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Usage : ->middleware('permission:sites.manage') ou 'permission:a,b' (toutes requises).
 * Vérifie aussi que le compte est actif (les routes /admin n'ont pas le middleware `tenant`).
 */
class RequirePermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        if (! $user || ! $user->isActive()) {
            return ApiResponse::error('account_disabled', 'Compte désactivé.', 403);
        }

        foreach ($permissions as $permission) {
            if (! $user->hasPermission($permission)) {
                return ApiResponse::error('forbidden', 'Permission insuffisante.', 403);
            }
        }

        return $next($request);
    }
}
