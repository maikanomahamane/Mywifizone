<?php

namespace App\Http\Middleware;

use App\Domain\Billing\SubscriptionStatus;
use App\Models\Subscription;
use App\Support\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * À placer après `tenant`, sur les routes d'ÉCRITURE métier. Les lectures restent ouvertes pour
 * qu'un abonnement expiré puisse consulter ses données et payer.
 */
class RequireActiveSubscription
{
    public function handle(Request $request, Closure $next): Response
    {
        $status = Subscription::value('status');

        if (! in_array($status, SubscriptionStatus::WRITABLE, true)) {
            throw new ApiException('subscription_inactive', 'Abonnement inactif : renouvelez pour continuer.', 402, ['status' => $status]);
        }

        return $next($request);
    }
}
