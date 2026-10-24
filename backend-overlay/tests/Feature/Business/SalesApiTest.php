<?php

namespace Tests\Feature\Business;

use App\Models\Sale;
use App\Models\Voucher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesNetwork;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class SalesApiTest extends TestCase
{
    use CreatesNetwork, CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    /** @return array{0: \App\Models\Tenant, 1: \App\Models\User, 2: \App\Models\User, 3: \App\Models\Router, 4: \App\Models\HotspotProfile} */
    private function shop(int $stock = 2): array
    {
        [$tenant, $owner] = $this->tenantWithOwner();
        $agent = $this->agentFor($tenant);
        [$router] = $this->enrolledRouter($tenant);
        $profile = $this->makeProfile($tenant);
        for ($i = 0; $i < $stock; $i++) {
            $this->makeVoucher($tenant, $router, $profile);
        }

        return [$tenant, $owner, $agent, $router, $profile];
    }

    private function cashSession($user, $tenant, int $opening = 1000, string $currency = 'XOF'): string
    {
        $register = $this->asTenant($tenant, fn () => \App\Models\CashRegister::create(['name' => 'Caisse', 'currency' => $currency, 'is_active' => true]));
        Sanctum::actingAs($user);

        return $this->postJson('/api/v1/cash-sessions', ['cash_register_id' => $register->id, 'opening_balance' => $opening])
            ->assertCreated()->json('data.id');
    }

    public function test_agent_sells_a_voucher_by_profile_and_gets_the_code_once(): void
    {
        [, , $agent, $router, $profile] = $this->shop();
        Sanctum::actingAs($agent);

        $res = $this->postJson('/api/v1/sales', ['router_id' => $router->id, 'profile_id' => $profile->id, 'payment_method' => 'CASH'])
            ->assertCreated()
            ->assertJsonPath('data.amount', 200)
            ->assertJsonPath('data.currency', 'XOF')
            ->assertJsonPath('data.status', 'COMPLETED')
            ->assertJsonPath('data.agent_id', $agent->id);

        $this->assertMatchesRegularExpression('/^TST\d{5}$/', $res->json('data.voucher_code'));
        $this->assertSame('SOLD', Voucher::withoutGlobalScopes()->find($res->json('data.voucher_id'))->status);

        // Le code n'apparaît plus dans les consultations ultérieures.
        $this->getJson('/api/v1/sales/'.$res->json('data.id'))->assertOk()->assertJsonPath('data.voucher_code', null);
    }

    public function test_replaying_client_ref_returns_the_original_sale_without_a_second_sale(): void
    {
        [, , $agent, $router, $profile] = $this->shop(3);
        Sanctum::actingAs($agent);
        $body = ['router_id' => $router->id, 'profile_id' => $profile->id, 'payment_method' => 'CASH', 'client_ref' => 'app-1'];

        $first = $this->postJson('/api/v1/sales', $body)->assertCreated()->json('data');
        $replay = $this->postJson('/api/v1/sales', $body)->assertOk()->json('data');

        $this->assertSame($first['id'], $replay['id']);
        $this->assertSame(1, Sale::withoutGlobalScopes()->count());
        $this->assertSame(1, Voucher::withoutGlobalScopes()->where('status', 'SOLD')->count());
    }

    public function test_no_stock_gives_409_and_creates_nothing(): void
    {
        [, , $agent, $router, $profile] = $this->shop(0);
        Sanctum::actingAs($agent);

        $this->postJson('/api/v1/sales', ['router_id' => $router->id, 'profile_id' => $profile->id, 'payment_method' => 'CASH'])
            ->assertStatus(409)->assertJsonPath('errors.0.code', 'no_voucher_available');
        $this->assertSame(0, Sale::withoutGlobalScopes()->count());
    }

    public function test_unsynced_voucher_is_never_sold(): void
    {
        [$tenant, , $agent, $router, $profile] = $this->shop(0);
        $this->makeVoucher($tenant, $router, $profile, ['sync_status' => Voucher::SYNC_PENDING]);
        Sanctum::actingAs($agent);

        $this->postJson('/api/v1/sales', ['router_id' => $router->id, 'profile_id' => $profile->id, 'payment_method' => 'CASH'])
            ->assertStatus(409)->assertJsonPath('errors.0.code', 'no_voucher_available');
    }

    public function test_cash_sale_is_recorded_in_the_open_cash_session(): void
    {
        [$tenant, , $agent, $router, $profile] = $this->shop();
        $session = $this->cashSession($agent, $tenant, 1000);

        $this->postJson('/api/v1/sales', ['router_id' => $router->id, 'profile_id' => $profile->id, 'payment_method' => 'CASH'])->assertCreated();

        $this->getJson("/api/v1/cash-sessions/$session")->assertOk()->assertJsonPath('data.current_balance', 1200);
        $types = collect($this->getJson("/api/v1/cash-sessions/$session/transactions")->json('data'))->pluck('type')->sort()->values()->all();
        $this->assertSame(['OPEN', 'SALE'], $types);
    }

    public function test_mobile_money_sale_does_not_touch_the_cash_session(): void
    {
        [$tenant, , $agent, $router, $profile] = $this->shop();
        $session = $this->cashSession($agent, $tenant, 1000);

        $this->postJson('/api/v1/sales', ['router_id' => $router->id, 'profile_id' => $profile->id, 'payment_method' => 'MOBILE_MONEY', 'provider_transaction_id' => 'MM-1'])->assertCreated();

        $this->getJson("/api/v1/cash-sessions/$session")->assertJsonPath('data.current_balance', 1000);
    }

    public function test_cash_session_can_be_required_by_configuration(): void
    {
        config(['wifizone.sales.require_cash_session_for_cash' => true]);
        [, , $agent, $router, $profile] = $this->shop();
        Sanctum::actingAs($agent);

        $this->postJson('/api/v1/sales', ['router_id' => $router->id, 'profile_id' => $profile->id, 'payment_method' => 'CASH'])
            ->assertStatus(409)->assertJsonPath('errors.0.code', 'cash_session_required');
        $this->assertSame(0, Voucher::withoutGlobalScopes()->where('status', 'SOLD')->count(), 'le voucher ne doit pas être marqué vendu');
    }

    public function test_duplicate_payment_reference_is_rejected(): void
    {
        [, , $agent, $router, $profile] = $this->shop(3);
        Sanctum::actingAs($agent);
        $body = ['router_id' => $router->id, 'profile_id' => $profile->id, 'payment_method' => 'MOBILE_MONEY', 'provider_transaction_id' => 'MM-42'];

        $this->postJson('/api/v1/sales', $body)->assertCreated();
        $this->postJson('/api/v1/sales', $body)->assertStatus(409)->assertJsonPath('errors.0.code', 'duplicate_reference');
        $this->assertSame(1, Voucher::withoutGlobalScopes()->where('status', 'SOLD')->count());
    }

    public function test_a_foreign_tenant_voucher_cannot_be_sold(): void
    {
        [, , $agent] = $this->shop(0);
        [$other] = $this->tenantWithOwner();
        [$router2] = $this->enrolledRouter($other);
        $foreign = $this->makeVoucher($other, $router2, $this->makeProfile($other));
        Sanctum::actingAs($agent);

        $this->postJson('/api/v1/sales', ['voucher_id' => $foreign->id, 'payment_method' => 'CASH'])->assertStatus(422);
        $this->assertSame('AVAILABLE', Voucher::withoutGlobalScopes()->find($foreign->id)->status);
    }

    public function test_invalid_payment_method_is_rejected(): void
    {
        [, , $agent, $router, $profile] = $this->shop();
        Sanctum::actingAs($agent);

        $this->postJson('/api/v1/sales', ['router_id' => $router->id, 'profile_id' => $profile->id, 'payment_method' => 'BITCOIN'])->assertStatus(422);
    }

    public function test_sales_are_blocked_when_subscription_is_inactive(): void
    {
        [$tenant] = $this->tenantWithOwner(trial: false);
        $agent = $this->agentFor($tenant);
        Sanctum::actingAs($agent);

        $this->postJson('/api/v1/sales', ['voucher_id' => '00000000-0000-0000-0000-000000000000', 'payment_method' => 'CASH'])->assertStatus(402);
    }

    public function test_agent_only_sees_own_sales_and_owner_sees_all(): void
    {
        [$tenant, $owner, $agent, $router, $profile] = $this->shop(2);
        $other = $this->agentFor($tenant);

        Sanctum::actingAs($agent);
        $mine = $this->postJson('/api/v1/sales', ['router_id' => $router->id, 'profile_id' => $profile->id, 'payment_method' => 'CASH'])->json('data.id');
        Sanctum::actingAs($other);
        $theirs = $this->postJson('/api/v1/sales', ['router_id' => $router->id, 'profile_id' => $profile->id, 'payment_method' => 'CASH'])->json('data.id');

        Sanctum::actingAs($agent);
        $this->getJson('/api/v1/sales')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $mine);
        $this->getJson('/api/v1/sales?agent_id='.$other->id)->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson("/api/v1/sales/$theirs")->assertStatus(403);

        Sanctum::actingAs($owner);
        $this->getJson('/api/v1/sales')->assertOk()->assertJsonPath('meta.total', 2);
    }

    public function test_sales_are_isolated_between_tenants(): void
    {
        [, , $agent, $router, $profile] = $this->shop();
        Sanctum::actingAs($agent);
        $id = $this->postJson('/api/v1/sales', ['router_id' => $router->id, 'profile_id' => $profile->id, 'payment_method' => 'CASH'])->json('data.id');

        [, $intruder] = $this->tenantWithOwner();
        Sanctum::actingAs($intruder);
        $this->getJson("/api/v1/sales/$id")->assertStatus(404);
        $this->getJson('/api/v1/sales')->assertOk()->assertJsonPath('meta.total', 0);
        $this->postJson("/api/v1/sales/$id/void", ['reason' => 'test'])->assertStatus(404);
    }

    public function test_only_owner_can_void_and_cash_is_reversed_and_voucher_revoked(): void
    {
        [$tenant, $owner, $agent, $router, $profile] = $this->shop();
        $session = $this->cashSession($agent, $tenant, 1000);
        $sale = $this->postJson('/api/v1/sales', ['router_id' => $router->id, 'profile_id' => $profile->id, 'payment_method' => 'CASH'])->json('data');

        $this->postJson("/api/v1/sales/{$sale['id']}/void", ['reason' => 'erreur'])->assertStatus(403);

        // Le propriétaire annule : l'écriture inverse va dans la session d'origine encore ouverte.
        Sanctum::actingAs($owner);
        $this->postJson("/api/v1/sales/{$sale['id']}/void", ['reason' => 'erreur de saisie'])
            ->assertOk()->assertJsonPath('data.status', 'VOIDED');
        $this->postJson("/api/v1/sales/{$sale['id']}/void", ['reason' => 'erreur de saisie'])->assertOk(); // idempotent

        $this->assertSame('REVOKED', Voucher::withoutGlobalScopes()->find($sale['voucher_id'])->status);
        $this->getJson("/api/v1/cash-sessions/$session")->assertJsonPath('data.current_balance', 1000);
        $this->assertSame(1, \App\Models\CashTransaction::withoutGlobalScopes()->where('type', 'ADJUSTMENT')->count(), 'une seule écriture inverse malgré le double appel');
    }

    public function test_dashboard_shows_today_revenue_scoped_by_role(): void
    {
        [$tenant, $owner, $agent, $router, $profile] = $this->shop(2);
        $other = $this->agentFor($tenant);
        Sanctum::actingAs($agent);
        $this->postJson('/api/v1/sales', ['router_id' => $router->id, 'profile_id' => $profile->id, 'payment_method' => 'CASH'])->assertCreated();
        Sanctum::actingAs($other);
        $this->postJson('/api/v1/sales', ['router_id' => $router->id, 'profile_id' => $profile->id, 'payment_method' => 'CASH'])->assertCreated();

        $this->getJson('/api/v1/dashboard')->assertOk()
            ->assertJsonPath('data.revenue_today.0.total', 200)
            ->assertJsonPath('data.revenue_today.0.sales_count', 1);

        Sanctum::actingAs($owner);
        $this->getJson('/api/v1/dashboard')->assertOk()
            ->assertJsonPath('data.revenue_today.0.total', 400)
            ->assertJsonPath('data.tickets.sold', 2)
            ->assertJsonStructure(['data' => ['network' => ['routers_online', 'routers_offline', 'routers_pending', 'connected_clients']]]);
    }
}
