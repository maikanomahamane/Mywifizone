<?php

namespace Tests\Feature\Business;

use App\Models\CashRegister;
use App\Models\CashTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class CashApiTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    private function register($tenant, string $currency = 'XOF'): CashRegister
    {
        return $this->asTenant($tenant, fn () => CashRegister::create(['name' => 'Caisse '.$currency, 'currency' => $currency, 'is_active' => true]));
    }

    public function test_owner_creates_a_register_agent_cannot(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner();

        Sanctum::actingAs($this->agentFor($tenant));
        $this->postJson('/api/v1/cash-registers', ['name' => 'X', 'currency' => 'XOF'])->assertStatus(403);

        Sanctum::actingAs($owner);
        $this->postJson('/api/v1/cash-registers', ['name' => 'Caisse principale', 'currency' => 'xof'])
            ->assertCreated()->assertJsonPath('data.currency', 'XOF');
        $this->postJson('/api/v1/cash-registers', ['name' => 'Y', 'currency' => 'ZZZ'])->assertStatus(422);
    }

    public function test_session_lifecycle_with_balance_and_difference(): void
    {
        [$tenant] = $this->tenantWithOwner();
        $agent = $this->agentFor($tenant);
        $register = $this->register($tenant);
        Sanctum::actingAs($agent);

        $id = $this->postJson('/api/v1/cash-sessions', ['cash_register_id' => $register->id, 'opening_balance' => 5000])
            ->assertCreated()->assertJsonPath('data.status', 'OPEN')->assertJsonPath('data.current_balance', 5000)->json('data.id');

        $this->getJson('/api/v1/cash-sessions/current')->assertOk()->assertJsonPath('data.id', $id);

        $this->postJson("/api/v1/cash-sessions/$id/transactions", ['type' => 'CASH_IN', 'amount' => 1000])->assertCreated()->assertJsonPath('data.current_balance', 6000);
        $this->postJson("/api/v1/cash-sessions/$id/transactions", ['type' => 'EXPENSE', 'amount' => 1500, 'note' => 'Électricité'])->assertCreated()->assertJsonPath('data.amount', -1500)->assertJsonPath('data.current_balance', 4500);
        $this->postJson("/api/v1/cash-sessions/$id/transactions", ['type' => 'WITHDRAWAL', 'amount' => 500])->assertCreated();

        // compté 3900 pour 4000 attendus : écart -100
        $this->postJson("/api/v1/cash-sessions/$id/close", ['counted_balance' => 3900])
            ->assertOk()->assertJsonPath('data.status', 'CLOSED')
            ->assertJsonPath('data.expected_balance', 4000)
            ->assertJsonPath('data.counted_balance', 3900)
            ->assertJsonPath('data.difference', -100);

        $this->postJson("/api/v1/cash-sessions/$id/transactions", ['type' => 'CASH_IN', 'amount' => 1])->assertStatus(409)->assertJsonPath('errors.0.code', 'cash_session_closed');
        $this->postJson("/api/v1/cash-sessions/$id/close", ['counted_balance' => 3900])->assertStatus(409);
        $this->getJson('/api/v1/cash-sessions/current')->assertOk()->assertJsonPath('data', null);
    }

    public function test_only_one_open_session_per_register_and_per_user(): void
    {
        [$tenant] = $this->tenantWithOwner();
        $a = $this->agentFor($tenant);
        $b = $this->agentFor($tenant);
        $r1 = $this->register($tenant);
        $r2 = $this->register($tenant, 'XAF');

        Sanctum::actingAs($a);
        $this->postJson('/api/v1/cash-sessions', ['cash_register_id' => $r1->id, 'opening_balance' => 0])->assertCreated();
        $this->postJson('/api/v1/cash-sessions', ['cash_register_id' => $r2->id, 'opening_balance' => 0])->assertStatus(409)->assertJsonPath('errors.0.code', 'cash_session_already_open');

        Sanctum::actingAs($b);
        $this->postJson('/api/v1/cash-sessions', ['cash_register_id' => $r1->id, 'opening_balance' => 0])->assertStatus(409)->assertJsonPath('errors.0.code', 'cash_session_already_open');
    }

    public function test_outflow_cannot_exceed_balance(): void
    {
        [$tenant] = $this->tenantWithOwner();
        $agent = $this->agentFor($tenant);
        Sanctum::actingAs($agent);
        $id = $this->postJson('/api/v1/cash-sessions', ['cash_register_id' => $this->register($tenant)->id, 'opening_balance' => 100])->json('data.id');

        $this->postJson("/api/v1/cash-sessions/$id/transactions", ['type' => 'EXPENSE', 'amount' => 101])->assertStatus(422)->assertJsonPath('errors.0.code', 'insufficient_cash');
        $this->postJson("/api/v1/cash-sessions/$id/transactions", ['type' => 'EXPENSE', 'amount' => -5])->assertStatus(422);
        $this->postJson("/api/v1/cash-sessions/$id/transactions", ['type' => 'SALE', 'amount' => 5])->assertStatus(422);
        $this->postJson("/api/v1/cash-sessions/$id/transactions", ['type' => 'ADJUSTMENT', 'amount' => 0])->assertStatus(422);
    }

    public function test_agent_cannot_see_or_operate_another_agents_session_but_owner_can(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner();
        $a = $this->agentFor($tenant);
        $b = $this->agentFor($tenant);
        Sanctum::actingAs($a);
        $id = $this->postJson('/api/v1/cash-sessions', ['cash_register_id' => $this->register($tenant)->id, 'opening_balance' => 100])->json('data.id');

        Sanctum::actingAs($b);
        $this->getJson("/api/v1/cash-sessions/$id")->assertStatus(403);
        $this->postJson("/api/v1/cash-sessions/$id/transactions", ['type' => 'CASH_IN', 'amount' => 5])->assertStatus(403);
        $this->postJson("/api/v1/cash-sessions/$id/close", ['counted_balance' => 100])->assertStatus(403);
        $this->getJson('/api/v1/cash-sessions')->assertOk()->assertJsonPath('meta.total', 0);

        Sanctum::actingAs($owner);
        $this->getJson('/api/v1/cash-sessions')->assertOk()->assertJsonPath('meta.total', 1);
        $this->postJson("/api/v1/cash-sessions/$id/close", ['counted_balance' => 100])->assertOk();
    }

    public function test_cash_is_isolated_between_tenants(): void
    {
        [$tenant] = $this->tenantWithOwner();
        $agent = $this->agentFor($tenant);
        $register = $this->register($tenant);
        Sanctum::actingAs($agent);
        $id = $this->postJson('/api/v1/cash-sessions', ['cash_register_id' => $register->id, 'opening_balance' => 100])->json('data.id');

        [$other, $intruder] = $this->tenantWithOwner();
        Sanctum::actingAs($intruder);
        $this->getJson("/api/v1/cash-sessions/$id")->assertStatus(404);
        $this->getJson("/api/v1/cash-sessions/$id/transactions")->assertStatus(404);
        $this->postJson("/api/v1/cash-sessions/$id/close", ['counted_balance' => 0])->assertStatus(404);
        $this->putJson("/api/v1/cash-registers/{$register->id}", ['name' => 'Piraté'])->assertStatus(404);
        $this->getJson('/api/v1/cash-registers')->assertOk()->assertJsonPath('meta.total', 0);

        $otherAgent = $this->agentFor($other);
        Sanctum::actingAs($otherAgent);
        $this->postJson('/api/v1/cash-sessions', ['cash_register_id' => $register->id, 'opening_balance' => 0])->assertStatus(422);
    }

    public function test_journal_is_append_only(): void
    {
        [$tenant] = $this->tenantWithOwner();
        $agent = $this->agentFor($tenant);
        Sanctum::actingAs($agent);
        $id = $this->postJson('/api/v1/cash-sessions', ['cash_register_id' => $this->register($tenant)->id, 'opening_balance' => 100])->json('data.id');

        $tx = CashTransaction::withoutGlobalScopes()->where('cash_session_id', $id)->firstOrFail();
        $tx->amount = 999999;
        $tx->save();
        $tx->delete();

        $fresh = CashTransaction::withoutGlobalScopes()->find($tx->id);
        $this->assertNotNull($fresh);
        $this->assertSame(100, $fresh->amount);
    }

    public function test_opening_a_cash_session_is_blocked_when_subscription_is_inactive(): void
    {
        [$tenant] = $this->tenantWithOwner(trial: false);
        $agent = $this->agentFor($tenant);
        $register = $this->register($tenant);
        Sanctum::actingAs($agent);

        $this->postJson('/api/v1/cash-sessions', ['cash_register_id' => $register->id, 'opening_balance' => 0])->assertStatus(402);
    }
}
