<?php

namespace App\Domain\Tenancy;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * À utiliser sur TOUT modèle métier rattaché à un tenant.
 * - scope global : toute lecture est filtrée sur le tenant courant (404 sur les accès cross-tenant) ;
 * - tenant_id est posé automatiquement à la création, jamais depuis la requête ;
 * - tenant_id est immuable.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function ($model): void {
            $context = app(TenantContext::class);

            if ($model->tenant_id === null) {
                if (! $context->has()) {
                    throw new LogicException(static::class.' : création impossible sans contexte tenant.');
                }
                $model->tenant_id = $context->id();

                return;
            }

            if ($context->has() && $model->tenant_id !== $context->id()) {
                throw new LogicException(static::class.' : tenant_id différent du tenant courant.');
            }
        });

        static::updating(function ($model): void {
            if ($model->isDirty('tenant_id')) {
                throw new LogicException(static::class.' : tenant_id est immuable.');
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class)->withoutGlobalScopes();
    }
}
