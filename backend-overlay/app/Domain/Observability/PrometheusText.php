<?php

namespace App\Domain\Observability;

/**
 * Format texte Prometheus (exposition 0.0.4). Pur : aucune dépendance. Les étiquettes sont échappées (\ " saut de ligne)
 * et les noms de métriques validés, pour qu'une valeur issue de la base ne puisse pas casser le format.
 */
final class PrometheusText
{
    /**
     * @param  list<array{name: string, help: string, type?: string, samples: list<array{labels?: array<string, string>, value: int|float}>}>  $metrics
     */
    public static function render(array $metrics): string
    {
        $out = '';
        foreach ($metrics as $m) {
            if (preg_match('/^[a-zA-Z_:][a-zA-Z0-9_:]*$/', $m['name']) !== 1) {
                continue;
            }
            $out .= '# HELP '.$m['name'].' '.str_replace(["\\", "\n"], ['\\\\', '\\n'], $m['help'])."\n";
            $out .= '# TYPE '.$m['name'].' '.($m['type'] ?? 'gauge')."\n";
            foreach ($m['samples'] as $s) {
                $out .= $m['name'].self::labels($s['labels'] ?? []).' '.self::number($s['value'])."\n";
            }
        }

        return $out;
    }

    /** @param array<string, string> $labels */
    private static function labels(array $labels): string
    {
        if ($labels === []) {
            return '';
        }
        $parts = [];
        foreach ($labels as $k => $v) {
            if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', (string) $k) !== 1) {
                continue;
            }
            $parts[] = $k.'="'.str_replace(["\\", '"', "\n"], ['\\\\', '\\"', '\\n'], (string) $v).'"';
        }

        return $parts === [] ? '' : '{'.implode(',', $parts).'}';
    }

    private static function number(int|float $v): string
    {
        return is_int($v) ? (string) $v : rtrim(rtrim(number_format($v, 6, '.', ''), '0'), '.');
    }
}
