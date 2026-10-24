<?php

namespace App\Domain\Portal;

/** Un lien externe est ouvert dans le navigateur : https uniquement, sans identifiants intégrés (user:pass@). */
final class ExternalUrl
{
    public static function isValid(?string $url): bool
    {
        if ($url === null || $url === '' || strlen($url) > 300 || preg_match('/[\s"\'<>\\\\]/', $url) === 1) {
            return false;
        }
        $parts = parse_url($url);
        if ($parts === false || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        return str_contains($parts['host'], '.');
    }
}
