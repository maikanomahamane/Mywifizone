<?php

namespace App\Domain\Network;

/**
 * Tout texte transmis à un routeur passe par ici. Les valeurs sont validées par liste blanche côté serveur
 * (jamais échappées) : aucune chaîne libre fournie par un utilisateur n'atteint l'agent. C'est la première
 * barrière contre l'injection de commandes ; l'agent doit en plus les traiter comme des DONNÉES (corps JSON),
 * jamais comme du script.
 */
final class RouterOsValue
{
    /** Alphabet sans caractères ambigus (pas de 0/O, 1/I/L) : lisible sur un ticket imprimé. */
    public const CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public const CODE_MIN = 6;

    public const CODE_MAX = 12;

    /** Débit « montée/descente » façon RouterOS : 512k, 2M, 2M/5M, 1500k/3M... */
    public static function isValidRateLimit(string $value): bool
    {
        return preg_match('/^\d{1,6}[kKmMgG]?(\/\d{1,6}[kKmMgG]?)?$/D', $value) === 1;
    }

    public static function isValidCode(string $code): bool
    {
        return preg_match('/^['.self::CODE_ALPHABET.']{'.self::CODE_MIN.','.self::CODE_MAX.'}$/D', $code) === 1;
    }

    /** Nom du profil côté routeur : préfixe fixe + 8 hexadécimaux de l'UUID (pas de nom libre). */
    public static function profileName(string $uuid): string
    {
        $hex = substr(str_replace('-', '', strtolower($uuid)), -8);

        return 'wz-'.$hex;
    }

    public static function isValidProfileName(string $name): bool
    {
        return preg_match('/^wz-[0-9a-f]{8}$/D', $name) === 1;
    }

    /** Génère un code aléatoire (CSPRNG) sans caractères ambigus. */
    public static function randomCode(int $length = 8): string
    {
        if ($length < self::CODE_MIN || $length > self::CODE_MAX) {
            throw new \InvalidArgumentException('Longueur de code hors bornes.');
        }

        $max = strlen(self::CODE_ALPHABET) - 1;
        $code = '';
        for ($i = 0; $i < $length; $i++) {
            $code .= self::CODE_ALPHABET[random_int(0, $max)];
        }

        return $code;
    }
}
