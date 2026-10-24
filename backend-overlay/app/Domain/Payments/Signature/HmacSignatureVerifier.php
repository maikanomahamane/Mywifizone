<?php

namespace App\Domain\Payments\Signature;

/**
 * Signature HMAC-SHA256 de `{timestamp}.{corps brut}` avec tolérance d'horloge (anti-rejeu).
 * Utilisée par le fournisseur mock ; un vrai fournisseur impose son propre schéma (à lire dans sa doc officielle).
 */
final class HmacSignatureVerifier
{
    public function __construct(private readonly int $toleranceSeconds = 300) {}

    public function sign(string $secret, int $timestamp, string $body): string
    {
        return 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    public function verify(string $secret, string $timestampHeader, string $signatureHeader, string $body, ?int $now = null): bool
    {
        // Un secret vide ne doit jamais valider quoi que ce soit.
        if ($secret === '' || $signatureHeader === '' || ! ctype_digit($timestampHeader)) {
            return false;
        }

        $timestamp = (int) $timestampHeader;
        $now ??= time();

        if (abs($now - $timestamp) > $this->toleranceSeconds) {
            return false;
        }

        return hash_equals($this->sign($secret, $timestamp, $body), $signatureHeader);
    }
}
