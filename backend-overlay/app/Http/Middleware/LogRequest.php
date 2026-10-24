<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Une ligne structurée par requête API (request_id et tenant_id viennent du contexte de log).
 * On journalise le MODÈLE de route (`portal/orders/{token}`), jamais l'URL réelle : elle peut contenir un jeton de commande
 * ou le slug d'un portail. Ni corps, ni en-têtes, ni paramètres de requête.
 */
class LogRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set('started_at', hrtime(true));

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $started = $request->attributes->get('started_at');
        $ms = is_int($started) ? (int) round((hrtime(true) - $started) / 1e6) : null;
        $status = $response->getStatusCode();

        Log::log($status >= 500 ? 'error' : 'info', 'http_request', [
            'method' => $request->method(),
            'route' => $request->route()?->uri() ?? 'unmatched',
            'status' => $status,
            'duration_ms' => $ms,
        ]);
    }
}
