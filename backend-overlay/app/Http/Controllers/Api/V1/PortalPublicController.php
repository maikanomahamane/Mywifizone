<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Portal\PortalLabels;
use App\Domain\Portal\PortalPageBuilder;
use App\Http\Controllers\Controller;
use App\Models\PaymentTransaction;
use App\Services\BrandingService;
use App\Services\PortalService;
use App\Support\ApiException;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Portail captif : points d'entrée PUBLICS (sans authentification). Le tenant vient du slug public ou du jeton de commande ;
 * rien de ce que le client envoie ne peut désigner un autre tenant. Aucun secret serveur n'est renvoyé.
 */
class PortalPublicController extends Controller
{
    public function __construct(private readonly PortalService $portal, private readonly BrandingService $branding) {}

    /** Page HTML responsive du portail (à afficher depuis la page de connexion du HotSpot). */
    public function page(Request $request, string $slug): Response
    {
        $ctx = $this->portal->resolve($slug);

        return $this->portal->run($ctx['tenant']->getKey(), function () use ($request, $slug, $ctx) {
            $this->portal->assertServing($ctx['tenant']);
            $brand = $this->branding->brand($ctx['tenant']);
            $js = (string) file_get_contents(resource_path('portal/portal.js'));
            $html = PortalPageBuilder::build($slug, $brand, $request->query('lang', $ctx['tenant']->locale), $request->getSchemeAndHttpHost().'/api/v1', $brand->logoPngBase64 !== null, $js);

            return response($html, 200, [
                'Content-Type' => 'text/html; charset=utf-8',
                'Cache-Control' => 'no-store',
                'X-Content-Type-Options' => 'nosniff',
                'Referrer-Policy' => 'no-referrer',
                // Le formulaire de connexion poste vers le routeur (http local) : form-action l'autorise ; aucune ressource tierce.
                'Content-Security-Policy' => "default-src 'none'; script-src 'unsafe-inline'; style-src 'unsafe-inline'; img-src 'self' data:; connect-src 'self'; form-action http: https:; frame-ancestors 'none'; base-uri 'none'",
            ]);
        });
    }

    public function show(Request $request, string $slug): JsonResponse
    {
        $request->validate(['router' => ['nullable', 'uuid']]);
        $ctx = $this->portal->resolve($slug);

        return $this->portal->run($ctx['tenant']->getKey(), function () use ($request, $ctx) {
            $this->portal->assertServing($ctx['tenant']);
            $b = $ctx['branding'];

            return ApiResponse::ok([
                'name' => $b->display_name ?: $ctx['tenant']->name,
                'locale' => PortalLabels::normalize($ctx['tenant']->locale),
                'brand' => ['color' => $b->primary_color, 'slogan' => $b->slogan, 'phone' => $b->phone, 'terms' => $b->terms, 'has_logo' => $b->logo_path !== null],
                'offers' => $this->portal->offers($request->query('router')),
            ]);
        });
    }

    public function logo(string $slug): Response
    {
        $ctx = $this->portal->resolve($slug);
        $bytes = $this->portal->run($ctx['tenant']->getKey(), fn () => $this->branding->logoBytes());
        if ($bytes === null) {
            throw new ApiException('not_found', 'Logo introuvable.', 404);
        }

        return response($bytes, 200, ['Content-Type' => 'image/png', 'Cache-Control' => 'public, max-age=300', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function createOrder(Request $request, string $slug): JsonResponse
    {
        $data = $request->validate([
            'profile_id' => ['required', 'uuid'],
            'router_id' => ['required', 'uuid'],
            'locale' => ['nullable', Rule::in(PortalLabels::LOCALES)],
            'customer_phone' => ['nullable', 'regex:/^\+?[0-9]{6,15}$/'],
            'client_ref' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_.-]+$/'],
            'provider' => ['nullable', 'regex:/^[a-z_]{2,32}$/'],
        ]);
        $ctx = $this->portal->resolve($slug);

        return $this->portal->run($ctx['tenant']->getKey(), function () use ($data, $ctx) {
            $this->portal->assertServing($ctx['tenant']);
            [$order, $tx] = $this->portal->createOrder($data);

            return ApiResponse::ok([
                'token' => $order->public_token,
                'status' => $order->status,
                'reference' => substr((string) $order->getKey(), 0, 8),
                'payment' => ['provider' => $tx->provider, 'instructions' => $tx->instructions],
            ], [], 201);
        });
    }

    public function orderStatus(string $token): JsonResponse
    {
        $order = $this->portal->findByToken($token);
        if (! $order) {
            throw new ApiException('not_found', 'Commande introuvable.', 404);
        }

        return $this->portal->run($order->tenant_id, fn () => ApiResponse::ok($this->portal->publicStatus($order->fresh())), );
    }
}
