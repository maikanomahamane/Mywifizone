<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Payments\Exceptions\InvalidWebhookException;
use App\Domain\Payments\Exceptions\ProviderNotConfiguredException;
use App\Http\Controllers\Controller;
use App\Services\PaymentService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/** Point d'entrée des webhooks : non authentifié, protégé par la signature propre à chaque fournisseur. */
class PaymentWebhookController extends Controller
{
    public function __construct(private readonly PaymentService $payments) {}

    public function __invoke(Request $request, string $provider): JsonResponse
    {
        $headers = [];
        foreach ($request->headers->all() as $name => $values) {
            $headers[strtolower($name)] = is_array($values) ? (string) ($values[0] ?? '') : (string) $values;
        }

        try {
            // Corps BRUT : la signature porte sur les octets reçus, pas sur du JSON ré-encodé.
            $outcome = $this->payments->handleWebhook($provider, $request->getContent(), $headers);
        } catch (InvalidWebhookException $e) {
            // Log (et non audit_logs) : endpoint public, on évite qu'un attaquant remplisse la table d'audit.
            Log::warning('payment.webhook_rejected', ['provider' => $provider, 'ip' => $request->ip(), 'reason' => $e->getMessage()]);

            return ApiResponse::error('invalid_signature', 'Webhook rejeté.', 401);
        } catch (ProviderNotConfiguredException) {
            return ApiResponse::error('provider_unavailable', 'Fournisseur indisponible.', 404);
        }

        return ApiResponse::ok(['outcome' => $outcome]);
    }
}
