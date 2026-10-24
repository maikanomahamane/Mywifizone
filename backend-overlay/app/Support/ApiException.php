<?php

namespace App\Support;

use RuntimeException;

/** Erreur métier rendue dans l'enveloppe standard. `errorCode` est la clé stable traduite par l'UI. */
class ApiException extends RuntimeException
{
    /** @param array<string, mixed> $context */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 422,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }
}
