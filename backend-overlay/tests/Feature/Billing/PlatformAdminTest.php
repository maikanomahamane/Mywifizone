<?php

namespace Tests\Feature\Billing;

use App\Domain\Payments\Signature\HmacSignatureVerifier;
use App\Models\Promotion;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class PlatformAdminTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    public function test_overview_is_reserved_to_the_super_admin(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner();

        $this->getJson('/api/v1/admin/overview')->assertStatus(401);

        foreach ([$owner, $this->agentFor($tenant)] as $user) {
            Sanctum::actingAs($user);
            $this->getJson('/api/v1/admin/overview')->assertStatus(403);
        }
    }

    public function test_overview_counts_tenants_subscriptions_and_revenue(): void
    {
        [, $ownerA] = $this->tenantWithOwner();
        [$suspended] = $this->tenantWithOwner();
        $this->tenantWithOwner(); // un troisième tenant, resté en essai
        Sanctum::actingAs($this->superAdmin());
        $this->postJson("/api/v1/admin/tenants/{$suspended->getKey()}/suspend")->assertOk();

        // Un paiement réel de bout en bout : checkout puis webhook signé.
        Sanctum::actingAs($ownerA);
        $reference = $this->postJson('/api/v1/subscription/checkout', ['plan_code' => 'PRO', 'billing_cycle' => 'MONTHLY', 'provider' => 'mock'])
            ->assertCreated()->json('data.payment.reference');

        $this->getJson('/api/v1/admin/overview')->assertStatus(403);

        Sanctum::actingAs($this->superAdmin());
        $overview = $this->getJson('/api/v1/admin/overview')->assertOk()->json('data');
        $this->assertSame(1, $overview['payments_in_flight']);
        $this->assertSame([], $overview['revenue']);

        $body = json_encode(['event_id' => 'evt-ov', 'type' => 'payment.succeeded', 'reference' => $reference, 'amount' => 5000, 'currency' => 'XOF']);
        $ts = time();
        $this->call('POST', '/api/v1/payments/webhooks/mock', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_MOCK_TIMESTAMP' => (string) $ts,
            'HTTP_X_MOCK_SIGNATURE' => (new HmacSignatureVerifier)->sign('test-secret', $ts, $body),
        ], $body)->assertOk();

        $overview = $this->getJson('/api/v1/admin/overview')->assertOk()->json('data');

        $this->assertSame(['ACTIVE' => 2, 'SUSPENDED' => 1], array_intersect_key($overview['tenants'], ['ACTIVE' => 0, 'SUSPENDED' => 0]));
        $this->assertSame(1, $overview['subscriptions']['ACTIVE']);
        $this->assertSame(1, $overview['subscriptions']['SUSPENDED']);
        $this->assertSame(1, $overview['subscriptions']['TRIAL']);
        $this->assertSame([['currency' => 'XOF', 'invoices' => 1, 'total' => 5000]], $overview['revenue']);
        $this->assertSame(0, $overview['payments_in_flight']);
        $this->assertTrue($overview['health']['database']);
        $this->assertTrue($overview['health']['cache']);
    }

    public function test_suspension_and_reactivation_are_idempotent_and_recompute_the_subscription(): void
    {
        [$tenant] = $this->tenantWithOwner();
        Sanctum::actingAs($this->superAdmin());

        $this->postJson("/api/v1/admin/tenants/{$tenant->getKey()}/suspend", ['reason' => 'impayé'])->assertOk()->assertJsonPath('data.status', 'SUSPENDED');
        $this->postJson("/api/v1/admin/tenants/{$tenant->getKey()}/suspend")->assertOk();
        $this->assertSame(1, \App\Models\AuditLog::where('action', 'tenant.suspended')->count());

        // L'essai court toujours : la réactivation restaure TRIAL.
        $this->postJson("/api/v1/admin/tenants/{$tenant->getKey()}/reactivate")
            ->assertOk()->assertJsonPath('data.status', 'ACTIVE')->assertJsonPath('data.subscription.status', 'TRIAL');
        $this->assertSame('ACTIVE', Tenant::findOrFail($tenant->getKey())->status);
    }

    public function test_reactivating_after_the_trial_ended_during_suspension_gives_expired(): void
    {
        [$tenant] = $this->tenantWithOwner();
        Sanctum::actingAs($this->superAdmin());
        $this->postJson("/api/v1/admin/tenants/{$tenant->getKey()}/suspend")->assertOk();

        $this->travel(20)->days();
        $this->artisan('subscriptions:process')->assertSuccessful(); // ignore les abonnements SUSPENDED

        $this->postJson("/api/v1/admin/tenants/{$tenant->getKey()}/reactivate")
            ->assertOk()->assertJsonPath('data.subscription.status', 'EXPIRED');
    }

    public function test_trial_extension_respects_max_redemptions(): void
    {
        Promotion::create(['code' => 'ONCE', 'type' => 'TRIAL_EXTENSION', 'value' => 7, 'max_redemptions' => 1]);
        $payload = fn (string $phone) => ['name' => 'A', 'business_name' => 'B', 'phone' => $phone, 'password' => 'motdepasse-solide', 'promo_code' => 'once'];

        $this->postJson('/api/v1/auth/register', $payload('+22790000001'))->assertCreated();
        $this->postJson('/api/v1/auth/register', $payload('+22790000002'))->assertStatus(422)->assertJsonPath('errors.0.field', 'promo_code');

        $this->assertDatabaseCount('tenants', 1); // la 2e inscription n'a rien créé
        $this->assertSame(1, Promotion::firstOrFail()->redemptions_count);
    }
}
