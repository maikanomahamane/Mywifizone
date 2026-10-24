<?php

namespace App\Domain\Observability;

/**
 * Règles d'alerte sur les compteurs (pures, testables). Une alerte = une situation qui demande une action humaine :
 * argent encaissé sans ticket livré, paiement coincé, routeurs muets, file de tâches bloquée.
 */
final class AlertRules
{
    public const CRITICAL = 'critical';

    public const WARNING = 'warning';

    /**
     * @param  array<string, int|float>  $m  compteurs (voir MetricsCollector::snapshot)
     * @param  array<string, int|float>  $t  seuils
     * @return list<array{rule: string, severity: string, value: int|float, threshold: int|float}>
     */
    public static function evaluate(array $m, array $t): array
    {
        $alerts = [];
        $add = function (string $rule, string $severity, string $metric, string $threshold, bool $strict = false) use (&$alerts, $m, $t): void {
            $value = $m[$metric] ?? 0;
            $limit = $t[$threshold] ?? 0;
            if ($strict ? $value > $limit : $value >= $limit && $limit > 0) {
                $alerts[] = ['rule' => $rule, 'severity' => $severity, 'value' => $value, 'threshold' => $limit];
            }
        };

        // Aucun seuil de tolérance : de l'argent est en jeu.
        $add('portal_orders_need_attention', self::CRITICAL, 'portal_orders_needs_attention', 'needs_attention_max', true);
        $add('payments_stuck', self::CRITICAL, 'payments_stuck', 'stuck_payments_max', true);
        $add('failed_jobs', self::WARNING, 'failed_jobs', 'failed_jobs_max', true);
        $add('vouchers_sync_failed', self::WARNING, 'vouchers_sync_failed', 'sync_failed_max', true);
        $add('queue_backlog', self::WARNING, 'queue_size', 'queue_size_max');
        $add('commands_stuck', self::WARNING, 'commands_pending_old', 'commands_pending_old_max', true);

        // Part de routeurs hors ligne (parmi les routeurs enrôlés) : un seuil relatif évite le bruit sur les petits parcs.
        $enrolled = ($m['routers_online'] ?? 0) + ($m['routers_offline'] ?? 0);
        if ($enrolled > 0 && ($t['offline_ratio_max'] ?? 0) > 0) {
            $ratio = ($m['routers_offline'] ?? 0) / $enrolled;
            if ($ratio >= $t['offline_ratio_max']) {
                $alerts[] = ['rule' => 'routers_offline', 'severity' => self::WARNING, 'value' => round($ratio, 2), 'threshold' => $t['offline_ratio_max']];
            }
        }

        return $alerts;
    }
}
