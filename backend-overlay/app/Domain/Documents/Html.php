<?php

namespace App\Domain\Documents;

/**
 * Seul point d'entrée des données utilisateur dans le HTML des PDF. Tout texte passe par e() ; aucun balisage utilisateur
 * n'est jamais accepté (nom d'entreprise, slogan, conditions, libellés de formule, références de paiement...).
 */
final class Html
{
    public static function e(?string $text): string
    {
        $clean = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $text);

        return htmlspecialchars($clean, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Texte multiligne : échappé, puis les retours à la ligne deviennent <br>. */
    public static function multiline(?string $text): string
    {
        return nl2br(self::e($text), false);
    }
}
