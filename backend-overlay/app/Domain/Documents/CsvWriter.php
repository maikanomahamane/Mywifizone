<?php

namespace App\Domain\Documents;

/**
 * CSV RFC 4180 protégé contre l'injection de formules : une cellule TEXTE qui commence par = + - @ tabulation ou retour
 * chariot est préfixée par une apostrophe (Excel/Sheets/LibreOffice ne l'évaluent plus). Les entiers ne sont pas touchés
 * (un montant négatif reste un nombre). Séparateur « ; » : c'est celui qu'Excel attend dans les paramètres régionaux
 * français, majoritaires dans les pays visés.
 */
final class CsvWriter
{
    public const SEPARATOR = ';';

    public const BOM = "\xEF\xBB\xBF";

    /** @param list<mixed> $cells */
    public static function row(array $cells): string
    {
        return implode(self::SEPARATOR, array_map([self::class, 'cell'], $cells))."\r\n";
    }

    public static function cell(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        $text = (string) $value;
        if ($text !== '' && in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            $text = "'".$text;
        }
        if (preg_match('/[;"\r\n]/', $text) === 1) {
            $text = '"'.str_replace('"', '""', $text).'"';
        }

        return $text;
    }
}
