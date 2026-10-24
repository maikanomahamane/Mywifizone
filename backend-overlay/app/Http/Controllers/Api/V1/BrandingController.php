<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Documents\HexColor;
use App\Domain\Documents\TicketQr;
use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\BrandingService;
use App\Support\ApiException;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Personnalisation des documents : textes, couleur, adresse de connexion (QR) et logo. */
class BrandingController extends Controller
{
    public function __construct(private readonly BrandingService $branding, private readonly AuditLogger $audit) {}

    public function show(): JsonResponse
    {
        return ApiResponse::ok($this->branding->current()->toPayload());
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'display_name' => ['sometimes', 'nullable', 'string', 'max:80'],
            'phone' => ['sometimes', 'nullable', 'string', 'regex:/^[0-9+()\-. ]{3,30}$/'],
            'slogan' => ['sometimes', 'nullable', 'string', 'max:120'],
            'terms' => ['sometimes', 'nullable', 'string', 'max:600'],
            'primary_color' => ['sometimes', 'nullable', function (string $a, mixed $v, Closure $fail) {
                if (! HexColor::isValid(is_string($v) ? $v : null)) {
                    $fail('Couleur au format #RRGGBB attendue.');
                }
            }],
            'portal_url' => ['sometimes', 'nullable', 'string', function (string $a, mixed $v, Closure $fail) {
                if (! TicketQr::isValidPortalUrl(is_string($v) ? $v : null)) {
                    $fail('Adresse http(s) valide attendue (200 caractères maximum, sans espace).');
                }
            }],
        ]);

        if (isset($data['primary_color'])) {
            $data['primary_color'] = strtoupper($data['primary_color']);
        }
        $branding = $this->branding->update($data);
        $this->audit->log('branding.updated', null, $branding, ['fields' => array_keys($data)]);

        return ApiResponse::ok($branding->toPayload());
    }

    public function uploadLogo(Request $request): JsonResponse
    {
        $request->validate(['logo' => ['required', 'file', 'max:512']]);   // Ko ; le type réel est vérifié sur le contenu
        $branding = $this->branding->storeLogo($request->file('logo'));
        $this->audit->log('branding.logo_updated', null, $branding);

        return ApiResponse::ok($branding->toPayload());
    }

    public function logo(): Response
    {
        $bytes = $this->branding->logoBytes();
        if ($bytes === null) {
            throw new ApiException('not_found', 'Aucun logo.', 404);
        }

        return new Response($bytes, 200, [
            'Content-Type' => 'image/png',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    public function deleteLogo(): JsonResponse
    {
        $this->branding->removeLogo();
        $this->audit->log('branding.logo_removed');

        return ApiResponse::ok($this->branding->current()->toPayload());
    }
}
