<?php

namespace App\Domain\Portal;

/** Identifiant public du portail : 16 caractères aléatoires (80 bits), alphabet sans ambiguïté. Ce n'est pas un secret d'authentification. */
final class PortalSlug
{
    private const ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

    public static function generate(int $length = 16): string
    {
        $out = '';
        $max = strlen(self::ALPHABET) - 1;
        for ($i = 0; $i < $length; $i++) {
            $out .= self::ALPHABET[random_int(0, $max)];
        }

        return $out;
    }

    public static function isValid(string $slug): bool
    {
        return preg_match('/^[a-z0-9]{8,24}$/', $slug) === 1;
    }

    /** Jeton de commande (secret du client) : 32 caractères aléatoires. */
    public static function token(): string
    {
        return self::generate(32);
    }
}
