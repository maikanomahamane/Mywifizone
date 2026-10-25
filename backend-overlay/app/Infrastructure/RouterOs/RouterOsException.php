<?php

namespace App\Infrastructure\RouterOs;

use RuntimeException;

/** Échec d'un appel RouterOS. `kind` : unreachable, timeout, tls, auth, forbidden (droits), target (destination interdite), not_found, http, invalid. Jamais de secret dans le message. */
final class RouterOsException extends RuntimeException
{
    public function __construct(public readonly string $kind, string $message, public readonly ?int $status = null)
    {
        parent::__construct($message);
    }

    /** Rejouer a-t-il un sens ? Faux pour un refus d'accès (le mot de passe ne changera pas tout seul). */
    public function isRetryable(): bool
    {
        return ! in_array($this->kind, ['auth', 'forbidden', 'tls', 'invalid', 'target'], true);
    }
}
