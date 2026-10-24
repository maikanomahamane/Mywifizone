<?php

namespace App\Domain\Tenancy;

/**
 * Tenant courant de la requête. Enregistré en "scoped" (réinitialisé entre requêtes/jobs).
 * Sans contexte, les modèles BelongsToTenant ne renvoient RIEN (fail-closed).
 */
final class TenantContext
{
    private ?string $tenantId = null;

    public function set(string $tenantId): void
    {
        $this->tenantId = $tenantId;
    }

    public function id(): ?string
    {
        return $this->tenantId;
    }

    public function has(): bool
    {
        return $this->tenantId !== null;
    }

    public function clear(): void
    {
        $this->tenantId = null;
    }

    /**
     * Exécute un bloc dans le contexte d'un tenant puis restaure le contexte précédent.
     * Sert aux traitements hors requête utilisateur (webhooks, commandes planifiées, inscription).
     */
    public function run(string $tenantId, callable $fn): mixed
    {
        $previous = $this->tenantId;
        $this->tenantId = $tenantId;

        try {
            return $fn();
        } finally {
            $this->tenantId = $previous;
        }
    }
}
