<?php

namespace App\Support;

use Monolog\LogRecord;

/**
 * Processeur Monolog : masque les valeurs sensibles qui se glisseraient dans le contexte d'un log
 * (mots de passe, jetons, secrets, codes de ticket). Défense en profondeur : le code ne doit déjà jamais les journaliser.
 */
final class LogRedactor
{
    private const PATTERN = '/(pass(word)?|secret|token|authorization|api[_-]?key|credential|^code$|voucher_code|signature|otp)/i';

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(context: self::redact($record->context), extra: self::redact($record->extra));
    }

    /** @param  array<mixed>  $data @return array<mixed> */
    public static function redact(array $data, int $depth = 0): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match(self::PATTERN, $key) === 1) {
                $data[$key] = '[redacted]';
            } elseif (is_array($value) && $depth < 6) {
                $data[$key] = self::redact($value, $depth + 1);
            }
        }

        return $data;
    }
}
