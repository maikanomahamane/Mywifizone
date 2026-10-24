<?php

namespace App\Services;

use App\Domain\Business\ReportPeriod;
use App\Models\Sale;
use Illuminate\Support\Facades\DB;

/**
 * Agrégations de ventes. Un seul GROUP BY SQL sur les index (tenant, jour, …) ; le regroupement en semaine/mois/année
 * se fait ensuite en PHP sur au plus (jours × devises × valeurs de dimension) lignes : la plage est bornée par
 * ReportPeriod::maxDays. Les ventes annulées sont exclues des totaux. Le tenant est imposé par le scope global.
 */
final class ReportService
{
    public const GROUPS = ['none', 'site', 'agent', 'profile', 'payment_method'];

    private const GROUP_COLUMN = ['site' => 'site_id', 'agent' => 'agent_id', 'profile' => 'profile_id', 'payment_method' => 'payment_method'];

    /**
     * @param  array{site_id?: ?string, agent_id?: ?string, profile_id?: ?string, currency?: ?string, payment_method?: ?string}  $filters
     * @return array{rows: list<array<string, mixed>>, totals: list<array<string, mixed>>}
     */
    public function sales(string $period, string $from, string $to, array $filters, string $groupBy = 'none'): array
    {
        $col = self::GROUP_COLUMN[$groupBy] ?? null;

        $q = Sale::query()
            ->where('status', Sale::COMPLETED)
            ->whereBetween('sold_on', [$from, $to])
            ->select(['sold_on', 'currency'])
            ->selectRaw('count(*) as sales_count, sum(amount) as total')
            ->groupBy('sold_on', 'currency');

        if ($col) {
            $q->addSelect($col)->groupBy($col);
        }
        foreach (['site_id', 'agent_id', 'profile_id', 'currency', 'payment_method'] as $f) {
            if (! empty($filters[$f])) {
                $q->where($f, $filters[$f]);
            }
        }

        $buckets = [];
        foreach ($q->get() as $r) {
            $start = ReportPeriod::bucket($period, substr((string) $r->sold_on, 0, 10));
            $key = $start.'|'.$r->currency.'|'.($col ? (string) $r->{$col} : '');
            $buckets[$key] ??= [
                'period_start' => $start,
                'currency' => $r->currency,
                'group' => $col ? ($r->{$col} !== null ? (string) $r->{$col} : null) : null,
                'sales_count' => 0,
                'total' => 0,
            ];
            $buckets[$key]['sales_count'] += (int) $r->sales_count;
            $buckets[$key]['total'] += (int) $r->total;
        }

        $rows = array_values($buckets);
        usort($rows, fn ($a, $b) => [$a['period_start'], $a['currency'], (string) $a['group']] <=> [$b['period_start'], $b['currency'], (string) $b['group']]);

        // Totaux par devise : on n'additionne jamais des devises différentes.
        $totals = [];
        foreach ($rows as $r) {
            $totals[$r['currency']] ??= ['currency' => $r['currency'], 'sales_count' => 0, 'total' => 0];
            $totals[$r['currency']]['sales_count'] += $r['sales_count'];
            $totals[$r['currency']]['total'] += $r['total'];
        }
        ksort($totals);

        return ['rows' => $rows, 'totals' => array_values($totals)];
    }

    /**
     * Recettes d'un jour local, par devise (tableau de bord).
     *
     * @return list<array{currency: string, sales_count: int, total: int}>
     */
    public function dayRevenue(string $date, ?string $agentId = null): array
    {
        return Sale::query()
            ->where('status', Sale::COMPLETED)
            ->where('sold_on', $date)
            ->when($agentId, fn ($q) => $q->where('agent_id', $agentId))
            ->select('currency')
            ->selectRaw('count(*) as sales_count, sum(amount) as total')
            ->groupBy('currency')->orderBy('currency')
            ->get()
            ->map(fn ($r) => ['currency' => $r->currency, 'sales_count' => (int) $r->sales_count, 'total' => (int) $r->total])
            ->all();
    }
}
