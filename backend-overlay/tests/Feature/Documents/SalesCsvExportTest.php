<?php

namespace Tests\Feature\Documents;

use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class SalesCsvExportTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    private function sale($tenant, $agent, string $day, int $amount, array $extra = []): void
    {
        $this->asTenant($tenant, fn () => Sale::create($extra + [
            'agent_id' => $agent->getKey(), 'amount' => $amount, 'currency' => 'XOF', 'payment_method' => 'CASH',
            'status' => Sale::COMPLETED, 'sold_at' => $day.' 10:00:00', 'sold_on' => $day,
        ]));
    }

    /** @return list<list<string>> lignes sans l'en-tête, BOM retiré */
    private function lines(string $csv): array
    {
        $csv = ltrim($csv, "\xEF\xBB\xBF");

        return array_values(array_filter(explode("\r\n", $csv), fn ($l) => $l !== ''));
    }

    public function test_exports_sales_as_csv_with_header_bom_and_filters(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner(['name' => 'T'], plan: 'PRO');
        $agent = $this->agentFor($tenant);
        $this->sale($tenant, $agent, '2026-10-05', 200);
        $this->sale($tenant, $agent, '2026-10-06', 300, ['payment_method' => 'MOBILE_MONEY', 'provider_transaction_id' => 'MM-1']);
        $this->sale($tenant, $agent, '2026-10-07', 999, ['status' => Sale::VOIDED]);
        $this->sale($tenant, $agent, '2026-09-01', 1000);
        Sanctum::actingAs($owner);

        $res = $this->get('/api/v1/exports/sales.csv?from=2026-10-01&to=2026-10-31', ['Accept' => 'application/json'])->assertOk();

        $this->assertStringContainsString('text/csv', $res->headers->get('Content-Type'));
        $this->assertStringContainsString('ventes-2026-10-01_2026-10-31.csv', $res->headers->get('Content-Disposition'));
        $content = $res->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        $lines = $this->lines($content);
        $this->assertSame('id;date;sold_at_utc;status;amount_minor;currency;payment_method;reference;agent;plan;site_id', $lines[0]);
        $this->assertCount(3, $lines, 'en-tête + 2 ventes (annulée et hors période exclues)');
        $this->assertStringContainsString(';2026-10-05;', $lines[1]);
        $this->assertStringContainsString(';200;XOF;CASH;', $lines[1]);
        $this->assertStringContainsString(';MM-1;', $lines[2]);

        $with = $this->get('/api/v1/exports/sales.csv?from=2026-10-01&to=2026-10-31&include_voided=1', ['Accept' => 'application/json'])->streamedContent();
        $this->assertCount(4, $this->lines($with));

        $only = $this->get('/api/v1/exports/sales.csv?from=2026-10-01&to=2026-10-31&payment_method=MOBILE_MONEY', ['Accept' => 'application/json'])->streamedContent();
        $this->assertCount(2, $this->lines($only));
    }

    public function test_formula_injection_is_neutralised_in_text_cells(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner(plan: 'PRO');
        $agent = $this->agentFor($tenant);
        $agent->forceFill(['name' => '=HYPERLINK("http://evil","x")'])->save();
        $this->sale($tenant, $agent, '2026-10-05', 200, ['provider_transaction_id' => "=cmd|' /C calc'!A0"]);
        $this->sale($tenant, $agent, '2026-10-06', 200, ['provider_transaction_id' => '@SUM(1+1)']);
        Sanctum::actingAs($owner);

        $csv = $this->get('/api/v1/exports/sales.csv?from=2026-10-01&to=2026-10-31', ['Accept' => 'application/json'])->streamedContent();

        $this->assertStringContainsString("'=cmd|' /C calc'!A0", $csv);
        $this->assertStringContainsString("'@SUM(1+1)", $csv);
        $this->assertStringContainsString('"\'=HYPERLINK(""http://evil"",""x"")"', $csv);
        $this->assertDoesNotMatchRegularExpression('/(^|;)=/m', $csv);
        $this->assertDoesNotMatchRegularExpression('/(^|;)@/m', $csv);
    }

    public function test_access_plan_and_validation_rules(): void
    {
        [$starterTenant, $starterOwner] = $this->tenantWithOwner(plan: 'STARTER');
        Sanctum::actingAs($starterOwner);
        $this->getJson('/api/v1/exports/sales.csv?from=2026-10-01&to=2026-10-31')->assertStatus(403)->assertJsonPath('errors.0.code', 'feature_unavailable');

        [$tenant, $owner] = $this->tenantWithOwner(plan: 'PRO');
        Sanctum::actingAs($this->agentFor($tenant));
        $this->getJson('/api/v1/exports/sales.csv?from=2026-10-01&to=2026-10-31')->assertStatus(403);

        Sanctum::actingAs($owner);
        $this->getJson('/api/v1/exports/sales.csv')->assertStatus(422);
        $this->getJson('/api/v1/exports/sales.csv?from=2026-10-05&to=2026-10-01')->assertStatus(422);
        $this->getJson('/api/v1/exports/sales.csv?from=2024-01-01&to=2026-12-31')->assertStatus(422);
        $this->getJson('/api/v1/exports/sales.csv?from=2026-10-01&to=2026-10-31&payment_method=BITCOIN')->assertStatus(422);
    }

    public function test_row_limit_and_tenant_isolation(): void
    {
        config(['documents.max_csv_rows' => 1]);
        [$tenant, $owner] = $this->tenantWithOwner(plan: 'PRO');
        $agent = $this->agentFor($tenant);
        $this->sale($tenant, $agent, '2026-10-05', 200);
        $this->sale($tenant, $agent, '2026-10-06', 300);
        Sanctum::actingAs($owner);
        $this->getJson('/api/v1/exports/sales.csv?from=2026-10-01&to=2026-10-31')->assertStatus(422)->assertJsonPath('errors.0.code', 'export_too_large');

        config(['documents.max_csv_rows' => 100]);
        [, $other] = $this->tenantWithOwner(plan: 'PRO');
        Sanctum::actingAs($other);
        $csv = $this->get('/api/v1/exports/sales.csv?from=2026-10-01&to=2026-10-31', ['Accept' => 'application/json'])->streamedContent();
        $this->assertCount(1, $this->lines($csv), 'seulement l\'en-tête : aucune vente du tenant voisin');
    }
}
