<?php

namespace App\Domain\Network;

/**
 * Cible d'une connexion directe : le client saisit une adresse, le SERVEUR s'y connecte. Sans garde-fous, ce serait un
 * outil pour sonder le réseau interne de l'hébergement (SSRF). Règles pures, testables sans réseau.
 */
final class RestTarget
{
    public static function isValidHostSyntax(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return true;
        }

        // Nom de domaine uniquement (pas d'IPv6 littéral, pas de port, de chemin ni d'identifiants dans le champ).
        return preg_match('/^(?=.{1,253}$)([A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)+[A-Za-z]{2,63}$/D', $host) === 1;
    }

    /** Vrai si le serveur ne doit PAS se connecter à cette adresse IP. */
    public static function isForbiddenIp(string $ip, bool $allowPrivate): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return true;
        }
        // Boucle locale, 0.0.0.0/8, 240.0.0.0/4, lien-local (dont 169.254.169.254 : métadonnées cloud), ::1, fe80::… : jamais.
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_RES_RANGE) === false) {
            return true;
        }
        if ($allowPrivate) {
            return false;
        }
        // 10/8, 172.16/12, 192.168/16, fc00::/7 puis 100.64/10 (CGNAT, VPN en maillage).
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE) === false) {
            return true;
        }

        return self::inCgnat($ip);
    }

    private static function inCgnat(string $ip): bool
    {
        $long = ip2long($ip);

        return $long !== false && ($long & 0xFFC00000) === 0x64400000;
    }

    /** @param array<int, int> $forbidden */
    public static function isAllowedPort(int $port, array $forbidden): bool
    {
        return $port >= 1 && $port <= 65535 && ! in_array($port, $forbidden, true);
    }
}
