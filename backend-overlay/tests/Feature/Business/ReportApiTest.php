<?php

namespace Tests\Feature\Business;

use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesNetwork;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class ReportApiTest extends TestCase
{
    use CreatesNetwork, CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    private function sale($tenant, $agent, string $day, int $amount, string $currency = 'XOF', string $method = 'CASH', string $status = Sale::COMPLETED, $siteId = null): void
    {
        $this->asTenant($tenant, fn () => Sale::create([
            'agent_id' => $agent->getKey(), 'site_id' => $siteId, 'amount' => $amount, 'currency' => $currency, 'payment_method' => $method,
            'status' => $status, 'sold_at' => $day.' 10:00:00', 'sold_on' => $day,
        ]));
    }

    public function test_daily_report_aggregates_per_day_and_currency_and_excludes_voided(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner();
        $agent = $this->agentFor($tenant);
        $this->sale($tenant, $agent, '2026-10-05', 200);
        $this->sale($tenant, $agent, '2026-10-05', 300);
        $this->sale($tenant, $agent, '2026-10-05', 999, status: Sale::VOIDED);
        $this->sale($tenant, $agent, '2026-10-06', 500);
        $this->sale($tenant, $agent, '2026-10-06', 7, 'USD');
        $this->sale($tenant, $agent, '2026-09-30', 1000); // hors plage

        Sanctum::actingAs($owner);
        $res = $this->getJson('/api/v1/reports/sales?period=daily&from=2026-10-01&to=2026-10-31')->assertOk();

        $this->assertSame([
            ['period_start' => '2026-10-05', 'currency' => 'XOF', 'group' => null, 'sales_count' => 2, 'total' => 500],
            ['period_start' => '2026-10-06', 'currency' => 'USD', 'group' => null, 'sales_count' => 1, 'total' => 7],
            ['period_start' => '2026-10-06', 'currency' => 'XOF', 'group' => null, 'sales_count' => 1, 'total' => 500],
        ], $res->json('data.rows'));
        // Jamais de somme entre devises.
        $this->assertSame([
            ['currency' => 'USD', 'sales_count' => 1, 'total' => 7],
            ['currency' => 'XOF', 'sales_count' => 3, 'total' => 1000],
        ], $res->json('data.totals'));
    }

    public function test_monthly_report_requires_the_reports_feature(): void
    {
        [$starterTenant, $starterOwner] = $this->tenantWithOwner(plan: 'STARTER');
        Sanctum::actingAs($starterOwner);
        $this->getJson('/api/v1/reports/sales?period=monthly&from=2026-01-01&to=2026-12-31')->assertStatus(403)->assertJsonPath('errors.0.code', 'feature_unavailable');
        $this->getJson('/api/v1/reports/sales?period=daily&from=2026-10-01&to=2026-10-02')->assertOk();

        [$tenant, $owner] = $this->tenantWithOwner(plan: 'PRO');
        $agent = $this->agentFor($tenant);
        $this->sale($tenant, $agent, '2026-10-05', 200);
        $this->sale($tenant, $agent, '2026-10-20', 300);
        $this->sale($tenant, $agent, '2026-11-02', 100);
        Sanctum::actingAs($owner);

        $rows = $this->getJson('/api/v1/reports/sales?period=monthly&from=2026-10-01&to=2026-11-30')->assertOk()->json('data.rows');
        $this->assertSame(['2026-10-01', 500, 2], [$rows[0]['period_start'], $rows[0]['total'], $rows[0]['sales_count']]);
        $this->assertSame(['2026-11-01', 100, 1], [$rows[1]['period_start'], $rows[1]['total'], $rows[1]['sales_count']]);

        $weekly = $this->getJson('/api/v1/reports/sales?period=weekly&from=2026-10-01&to=2026-10-31')->json('data.rows');
        $this->assertSame(['2026-10-05', '2026-10-19'], array_column($weekly, 'period_start')); // lundis ISO
        $annual = $this->getJson('/api/v1/reports/sales?period=annual&from=2026-01-01&to=2026-12-31')->json('data.rows');
        $this->assertSame([['2026-01-01', 600]], array_map(fn ($r) => [$r['period_start'], $r['total']], $annual));
    }

    public function test_group_by_agent_and_filters(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner();
        $a = $this->agentFor($tenant);
        $b = $this->agentFor($tenant);
        $this->sale($tenant, $a, '2026-10-05', 200, method: 'CASH');
        $this->sale($tenant, $b, '2026-10-05', 300, method: 'MOBILE_MONEY');
        Sanctum::actingAs($owner);

        $rows = $this->getJson('/api/v1/reports/sales?period=daily&from=2026-10-05&to=2026-10-05&group_by=agent')->assertOk()->json('data.rows');
        $byAgent = collect($rows)->pluck('total', 'group')->all();
        $this->assertSame([$a->id => 200, $b->id => 300], $byAgent);

        $only = $this->getJson('/api/v1/reports/sales?period=daily&from=2026-10-05&to=2026-10-05&payment_method=MOBILE_MONEY')->json('data.totals');
        $this->assertSame([['currency' => 'XOF', 'sales_count' => 1, 'total' => 300]], $only);
        $this->assertSame([], $this->getJson('/api/v1/reports/sales?period=daily&from=2026-10-05&to=2026-10-05&currency=usd')->json('data.rows'));
    }

    public function test_reports_are_tenant_isolated_and_owner_only(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner();
        $agent = $this->agentFor($tenant);
        $this->sale($tenant, $agent, '2026-10-05', 200);

        Sanctum::actingAs($agent);
        $this->getJson('/api/v1/reports/sales?period=daily&from=2026-10-05&to=2026-10-05')->assertStatus(403);

        [, $other] = $this->tenantWithOwner();
        Sanctum::actingAs($other);
        $this->getJson('/api/v1/reports/sales?period=daily&from=2026-10-05&to=2026-10-05')->assertOk()->assertJsonPath('data.rows', []);
        // Filtrer sur un site d'un autre tenant est refusé (pas de fuite d'existence).
        $site = $this->makeSite($tenant);
        $this->getJson('/api/v1/reports/sales?period=daily&from=2026-10-05&to=2026-10-05&site_id='.$site->id)->assertStatus(422);
        unset($owner);
    }

    public function test_range_and_params_are_validated(): void
    {
        [, $owner] = $this->tenantWithOwner();
        Sanctum::actingAs($owner);

        $this->getJson('/api/v1/reports/sales?period=daily&from=2026-01-01&to=2027-12-31')->assertStatus(422);   // > 366 jours
        $this->getJson('/api/v1/reports/sales?period=daily&from=2026-10-05&to=2026-10-01')->assertStatus(422);   // to < from
        $this->getJson('/api/v1/reports/sales?period=hourly&from=2026-10-01&to=2026-10-02')->assertStatus(422);
        $this->getJson('/api/v1/reports/sales?period=daily')->assertStatus(422);
    }
}
