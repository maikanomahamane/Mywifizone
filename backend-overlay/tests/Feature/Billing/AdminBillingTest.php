<?php

namespace Tests\Feature\Billing;

use App\Models\Promotion;
use App\Models\SubscriptionPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class AdminBillingTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    private function planId(string $code): string
    {
        return SubscriptionPlan::where('code', $code)->firstOrFail()->getKey();
    }

    public function test_super_admin_can_change_prices_and_limits_without_touching_existing_invoices(): void
    {
        [, $owner] = $this->tenantWithOwner();
        Sanctum::actingAs($owner);
        $oldInvoice = $this->postJson('/api/v1/subscription/checkout', ['plan_code' => 'PRO', 'billing_cycle' => 'MONTHLY', 'provider' => 'mock'])->assertCreated()->json('data.invoice');

        Sanctum::actingAs($this->superAdmin());
        $this->putJson('/api/v1/admin/plans/'.$this->planId('PRO'), [
            'monthly_price' => 6000, 'yearly_price' => 60000, 'limits' => ['max_sites' => 5], 'trial_days' => 30,
        ])->assertOk()->assertJsonPath('data.monthly_price', 6000)->assertJsonPath('data.limits.max_sites', 5)->assertJsonPath('data.limits.max_routers', 5);

        $this->assertDatabaseHas('audit_logs', ['action' => 'plan.updated']);

        Sanctum::actingAs($owner);
        $this->assertSame(5000, $oldInvoice['total']);
        $new = $this->postJson('/api/v1/subscription/checkout', ['plan_code' => 'PRO', 'billing_cycle' => 'YEARLY', 'provider' => 'mock'], ['Idempotency-Key' => 'k2'])->assertCreated()->json('data.invoice');
        $this->assertSame(60000, $new['total']);
    }

    public function test_super_admin_can_make_a_limit_unlimited_with_null(): void
    {
        Sanctum::actingAs($this->superAdmin());

        $this->putJson('/api/v1/admin/plans/'.$this->planId('STARTER'), ['limits' => ['max_sites' => null]])
            ->assertOk()->assertJsonPath('data.limits.max_sites', null);
    }

    public function test_owner_and_agent_cannot_use_admin_routes(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner();

        foreach ([$owner, $this->agentFor($tenant)] as $user) {
            Sanctum::actingAs($user);
            $this->getJson('/api/v1/admin/plans')->assertStatus(403);
            $this->putJson('/api/v1/admin/plans/'.$this->planId('PRO'), ['monthly_price' => 1])->assertStatus(403);
            $this->postJson('/api/v1/admin/promotions', ['code' => 'X', 'type' => 'PERCENTAGE', 'value' => 10])->assertStatus(403);
            $this->getJson('/api/v1/admin/payment-providers')->assertStatus(403);
        }

        $this->assertSame(5000, SubscriptionPlan::where('code', 'PRO')->firstOrFail()->monthly_price);
    }

    public function test_admin_routes_require_authentication(): void
    {
        $this->getJson('/api/v1/admin/plans')->assertStatus(401);
    }

    public function test_plan_validation_rejects_bad_values(): void
    {
        Sanctum::actingAs($this->superAdmin());
        $id = $this->planId('PRO');

        $this->putJson("/api/v1/admin/plans/$id", ['monthly_price' => -1])->assertStatus(422);
        $this->putJson("/api/v1/admin/plans/$id", ['currency' => 'ZZZ'])->assertStatus(422);
        $this->putJson("/api/v1/admin/plans/$id", ['limits' => ['unknown_key' => 3]])->assertStatus(422);
        $this->putJson('/api/v1/admin/plans/not-a-uuid', ['monthly_price' => 1])->assertStatus(404);
    }

    public function test_admin_creates_promotions_with_coherence_checks(): void
    {
        Sanctum::actingAs($this->superAdmin());

        $this->postJson('/api/v1/admin/promotions', ['code' => 'rentree', 'type' => 'PERCENTAGE', 'value' => 15])
            ->assertCreated()->assertJsonPath('data.code', 'RENTREE');

        $this->postJson('/api/v1/admin/promotions', ['code' => 'rentree', 'type' => 'PERCENTAGE', 'value' => 15])->assertStatus(422); // doublon
        $this->postJson('/api/v1/admin/promotions', ['code' => 'TROP', 'type' => 'PERCENTAGE', 'value' => 150])->assertStatus(422);
        $this->postJson('/api/v1/admin/promotions', ['code' => 'FIXE', 'type' => 'FIXED', 'value' => 500])->assertStatus(422); // devise manquante
        $this->postJson('/api/v1/admin/promotions', ['code' => 'FIXE2', 'type' => 'FIXED', 'value' => 500, 'currency' => 'XOF'])->assertCreated();

        $id = Promotion::where('code', 'RENTREE')->firstOrFail()->getKey();
        $this->putJson("/api/v1/admin/promotions/$id", ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
    }

    public function test_provider_activation_does_not_make_an_unconfigured_adapter_usable(): void
    {
        Sanctum::actingAs($this->superAdmin());

        $providers = $this->getJson('/api/v1/admin/payment-providers')->assertOk()->json('data');
        $mock = collect($providers)->firstWhere('code', 'mock');
        $this->assertTrue($mock['credentials_configured']);

        $this->putJson('/api/v1/admin/payment-providers/wave', ['enabled' => true])
            ->assertOk()->assertJsonPath('data.enabled', true)->assertJsonPath('data.credentials_configured', false);

        [, $owner] = $this->tenantWithOwner();
        Sanctum::actingAs($owner);
        $usable = collect($this->getJson('/api/v1/subscription/providers')->assertOk()->json('data'))->pluck('code')->all();

        $this->assertContains('mock', $usable);
        $this->assertNotContains('wave', $usable);
    }

    public function test_disabling_a_provider_blocks_new_checkouts(): void
    {
        Sanctum::actingAs($this->superAdmin());
        $this->putJson('/api/v1/admin/payment-providers/mock', ['enabled' => false])->assertOk();

        [, $owner] = $this->tenantWithOwner();
        Sanctum::actingAs($owner);
        $this->postJson('/api/v1/subscription/checkout', ['plan_code' => 'PRO', 'billing_cycle' => 'MONTHLY', 'provider' => 'mock'])
            ->assertStatus(422)->assertJsonPath('errors.0.code', 'provider_unavailable');
    }
}
