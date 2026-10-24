<?php

namespace App\Services;

use App\Models\Subscription;
use App\Support\ApiException;

/** Limites et fonctionnalités du plan du tenant courant (contexte tenant requis). */
final class PlanEntitlements
{
    public function subscription(): ?Subscription
    {
        return Subscription::with('plan')->first();
    }

    /** null = illimité. Sans abonnement ou clé absente : 0 (refus par défaut). */
    public function limit(string $key): ?int
    {
        $plan = $this->subscription()?->plan;
        $limits = $plan?->limits ?? [];

        return array_key_exists($key, $limits) ? $limits[$key] : 0;
    }

    public function canAdd(string $key, int $current): bool
    {
        $limit = $this->limit($key);

        return $limit === null || $current < $limit;
    }

    public function hasFeature(string $feature): bool
    {
        return in_array($feature, $this->subscription()?->plan?->features ?? [], true);
    }

    /** Comme limit(), mais avec une valeur par défaut quand la clé n'existe pas dans le plan (clés ajoutées après coup). */
    public function limitOr(string $key, ?int $default): ?int
    {
        $limits = $this->subscription()?->plan?->limits ?? [];

        return array_key_exists($key, $limits) ? $limits[$key] : $default;
    }

    /** @throws ApiException 403 plan_limit_reached */
    public function assertCanAdd(string $key, int $current): void
    {
        if (! $this->canAdd($key, $current)) {
            throw new ApiException('plan_limit_reached', 'Limite atteinte pour votre formule.', 403, [
                'limit' => $key,
                'max' => $this->limit($key),
            ]);
        }
    }

    /** @throws ApiException 403 feature_unavailable */
    public function assertFeature(string $feature): void
    {
        if (! $this->hasFeature($feature)) {
            throw new ApiException('feature_unavailable', 'Fonctionnalité non incluse dans votre formule.', 403, ['feature' => $feature]);
        }
    }
}
