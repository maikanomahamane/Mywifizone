<?php

namespace App\Domain\Documents;

/**
 * Contenu du QR d'un ticket. Il ne contient AUCUN secret du serveur : seulement le code du ticket (qui est déjà
 * imprimé en clair sur le ticket et sert d'identifiant ET de mot de passe HotSpot) et, si le propriétaire l'a
 * renseignée, l'adresse de connexion de SON HotSpot. L'adresse n'est jamais appelée par le serveur (pas de SSRF).
 */
final class TicketQr
{
    public static function payload(string $code, ?string $portalUrl): string
    {
        if ($portalUrl === null || ! self::isValidPortalUrl($portalUrl)) {
            return $code;
        }

        $sep = str_contains($portalUrl, '?') ? '&' : '?';

        return $portalUrl.$sep.'username='.rawurlencode($code).'&password='.rawurlencode($code);
    }

    /** http(s) uniquement, sans espace ni caractère de contrôle, longueur bornée. */
    public static function isValidPortalUrl(?string $url): bool
    {
        return is_string($url)
            && strlen($url) <= 200
            && preg_match('#^https?://[^\s\x00-\x1F"\'<>\\\\]+$#i', $url) === 1;
    }
}
