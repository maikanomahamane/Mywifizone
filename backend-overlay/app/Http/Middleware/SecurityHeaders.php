<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * En-têtes de défense pour toute l'API. Ne remplace jamais un en-tête déjà posé (la page du portail définit sa propre CSP,
 * le logo son cache). HSTS seulement si la requête est déjà en HTTPS (derrière un proxy de confiance : TRUSTED_PROXIES).
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $h = $response->headers;

        $defaults = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'no-referrer',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=()',
            'Cross-Origin-Resource-Policy' => 'same-site',
        ];
        foreach ($defaults as $name => $value) {
            if (! $h->has($name)) {
                $h->set($name, $value);
            }
        }
        // Les réponses de l'API contiennent des données de compte : jamais de cache partagé.
        if (! $h->has('Cache-Control') || $h->get('Cache-Control') === 'no-cache, private') {
            $h->set('Cache-Control', 'no-store');
        }
        if ($request->isSecure() && ! $h->has('Strict-Transport-Security')) {
            $h->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
