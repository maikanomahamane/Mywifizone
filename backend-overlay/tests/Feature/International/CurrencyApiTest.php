<?php

namespace Tests\Feature\International;

use App\Models\ExchangeRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesNetwork;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class CurrencyApiTest extends TestCase
{
    use CreatesNetwork, CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    public function test_lists_the_eight_initial_currencies_with_minor_units(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner();
        Sanctum::actingAs($owner);

        $res = $this->getJson('/api/v1/currencies')->assertOk();
        $codes = collect($res->json('data'))->pluck('minor_unit', 'code')->all();
        $this->assertSame(['EUR' => 2, 'GBP' => 2, 'GHS' => 2, 'KES' => 2, 'NGN' => 2, 'USD' => 2, 'XAF' => 0, 'XOF' => 0], $codes);
    }

    public function test_owner_updates_regional_settings_without_touching_prices(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner(['currency' => 'XOF'], plan: 'PRO');
        $profile = $this->makeProfile($tenant, ['price' => 200, 'currency' => 'XOF']);
        Sanctum::actingAs($owner);

        $this->putJson('/api/v1/tenants/me', ['currency' => 'NGN', 'locale' => 'ha', 'country' => 'NG', 'timezone' => 'Africa/Lagos'])
            ->assertOk()->assertJsonPath('data.currency', 'NGN')->assertJsonPath('data.locale', 'ha')->assertJsonPath('data.timezone', 'Africa/Lagos');

        $this->assertSame(200, (int) $this->asTenant($tenant, fn () => $profile->fresh()->price));
        $this->assertSame('XOF', $this->asTenant($tenant, fn () => $profile->fresh()->currency));
        $this->getJson('/api/v1/tenants/me')->assertJsonPath('data.currency', 'NGN');
    }

    public function test_settings_validation_and_permissions(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner();
        Sanctum::actingAs($owner);
        $this->putJson('/api/v1/tenants/me', ['currency' => 'ZZZ'])->assertStatus(422);
        $this->putJson('/api/v1/tenants/me', ['locale' => 'de'])->assertStatus(422);
        $this->putJson('/api/v1/tenants/me', ['country' => 'niger'])->assertStatus(422);
        $this->putJson('/api/v1/tenants/me', ['timezone' => 'Mars/Olympus'])->assertStatus(422);

        Sanctum::actingAs($this->agentFor($tenant));
        $this->putJson('/api/v1/tenants/me', ['locale' => 'en'])->assertStatus(403);
    }

    public function test_peg_conversion_xof_eur_and_identity(): void
    {
        [, $owner] = $this->tenantWithOwner(plan: 'PRO');
        Sanctum::actingAs($owner);

        $this->getJson('/api/v1/currencies/convert?amount=1000&from=EUR&to=XOF')   // 10,00 EUR
            ->assertOk()->assertJsonPath('data.amount', 6560)->assertJsonPath('data.source', 'peg')->assertJsonPath('data.indicative', true);
        $this->getJson('/api/v1/currencies/convert?amount=1000000&from=xof&to=eur')
            ->assertOk()->assertJsonPath('data.amount', 152449);
        $this->getJson('/api/v1/currencies/convert?amount=500&from=XOF&to=XOF')->assertOk()->assertJsonPath('data.source', 'identity')->assertJsonPath('data.amount', 500);
    }

    public function test_manual_rates_crud_conversion_and_validation(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner(plan: 'PRO');
        Sanctum::actingAs($owner);

        $this->getJson('/api/v1/currencies/convert?amount=1000&from=USD&to=XOF')->assertStatus(422)->assertJsonPath('errors.0.code', 'rate_unavailable');

        $id = $this->putJson('/api/v1/exchange-rates', ['from' => 'usd', 'to' => 'xof', 'rate' => '610.5'])
            ->assertOk()->assertJsonPath('data.rate', '610.5')->json('data.id');
        $this->getJson('/api/v1/currencies/convert?amount=1000&from=USD&to=XOF')   // 10,00 USD
            ->assertOk()->assertJsonPath('data.amount', 6105)->assertJsonPath('data.source', 'manual');
        $this->putJson('/api/v1/exchange-rates', ['from' => 'USD', 'to' => 'XOF', 'rate' => '620'])->assertOk();
        $this->assertSame(1, ExchangeRate::withoutGlobalScopes()->where('tenant_id', $tenant->getKey())->count());

        $list = $this->getJson('/api/v1/exchange-rates')->assertOk()->json('data');
        $this->assertContains('peg', array_column($list, 'source'));
        $this->assertContains('manual', array_column($list, 'source'));

        foreach (['0', '-5', '1e3', 'abc', '1.123456789', '99999999', ''] as $bad) {
            $this->putJson('/api/v1/exchange-rates', ['from' => 'USD', 'to' => 'GHS', 'rate' => $bad])->assertStatus(422);
        }
        $this->putJson('/api/v1/exchange-rates', ['from' => 'EUR', 'to' => 'XOF', 'rate' => '600'])->assertStatus(422)->assertJsonPath('errors.0.code', 'peg_rate_fixed');
        $this->putJson('/api/v1/exchange-rates', ['from' => 'USD', 'to' => 'USD', 'rate' => '1'])->assertStatus(422);
        $this->putJson('/api/v1/exchange-rates', ['from' => 'USD', 'to' => 'ZZZ', 'rate' => '1'])->assertStatus(422);

        $this->deleteJson("/api/v1/exchange-rates/$id")->assertOk();
        $this->getJson('/api/v1/currencies/convert?amount=1000&from=USD&to=XOF')->assertStatus(422);
    }

    public function test_rates_require_plan_feature_and_owner_and_are_tenant_isolated(): void
    {
        [, $starterOwner] = $this->tenantWithOwner(plan: 'STARTER');
        Sanctum::actingAs($starterOwner);
        $this->getJson('/api/v1/exchange-rates')->assertStatus(403)->assertJsonPath('errors.0.code', 'feature_unavailable');
        $this->getJson('/api/v1/currencies/convert?amount=1&from=EUR&to=XOF')->assertStatus(403);
        $this->putJson('/api/v1/exchange-rates', ['from' => 'USD', 'to' => 'XOF', 'rate' => '600'])->assertStatus(403);

        [$tenantA, $ownerA] = $this->tenantWithOwner(plan: 'PRO');
        Sanctum::actingAs($ownerA);
        $id = $this->putJson('/api/v1/exchange-rates', ['from' => 'USD', 'to' => 'XOF', 'rate' => '600'])->json('data.id');
        Sanctum::actingAs($this->agentFor($tenantA));
        $this->getJson('/api/v1/exchange-rates')->assertStatus(403);

        [, $ownerB] = $this->tenantWithOwner(plan: 'PRO');
        Sanctum::actingAs($ownerB);
        $this->getJson('/api/v1/currencies/convert?amount=1000&from=USD&to=XOF')->assertStatus(422);   // le taux de A est invisible
        $this->deleteJson("/api/v1/exchange-rates/$id")->assertStatus(404);
    }
}
