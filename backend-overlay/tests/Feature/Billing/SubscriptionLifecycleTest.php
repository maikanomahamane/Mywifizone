<?php

namespace Tests\Feature\Billing;

use App\Models\Promotion;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class SubscriptionLifecycleTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    private function register(array $extra = [])
    {
        return $this->postJson('/api/v1/auth/register', array_merge([
            'name' => 'Ousmane', 'business_name' => 'Zone Maradi', 'phone' => '+22790123456', 'password' => 'motdepasse-solide',
        ], $extra));
    }

    private function subscription(): Subscription
    {
        return Subscription::withoutGlobalScopes()->with('plan')->firstOrFail();
    }

    public function test_plans_are_public_and_read_from_the_database(): void
    {
        $this->getJson('/api/v1/plans')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.code', 'STARTER')
            ->assertJsonPath('data.0.monthly_price', 2000)
            ->assertJsonPath('data.0.currency', 'XOF')
            ->assertJsonPath('data.0.limits.max_sites', 1)
            ->assertJsonPath('data.2.monthly_price', 10000);
    }

    public function test_registration_starts_a_14_day_trial_on_the_default_plan(): void
    {
        $this->register()->assertCreated()->assertJsonPath('data.user.subscription.status', 'TRIAL');

        $subscription = $this->subscription();
        $this->assertSame('STARTER', $subscription->plan->code);
        $this->assertEqualsWithDelta(now()->addDays(14)->timestamp, $subscription->trial_ends_at->timestamp, 120);
    }

    public function test_registration_can_choose_the_plan_and_use_a_trial_extension(): void
    {
        Promotion::create(['code' => 'BONUS7', 'type' => 'TRIAL_EXTENSION', 'value' => 7]);

        $this->register(['plan_code' => 'PRO', 'promo_code' => 'bonus7'])->assertCreated();

        $subscription = $this->subscription();
        $this->assertSame('PRO', $subscription->plan->code);
        $this->assertEqualsWithDelta(now()->addDays(21)->timestamp, $subscription->trial_ends_at->timestamp, 120);
        $this->assertSame(1, Promotion::firstOrFail()->redemptions_count);
    }

    public function test_a_discount_code_cannot_be_used_as_a_trial_extension(): void
    {
        Promotion::create(['code' => 'PROMO20', 'type' => 'PERCENTAGE', 'value' => 20]);

        $this->register(['promo_code' => 'PROMO20'])->assertStatus(422)->assertJsonPath('errors.0.field', 'promo_code');
        $this->assertDatabaseCount('tenants', 0); // rien n'est créé à moitié
    }

    public function test_unknown_plan_is_refused_at_registration(): void
    {
        $this->register(['plan_code' => 'NOPE'])->assertStatus(422);
    }

    public function test_starter_site_limit_is_enforced_server_side(): void
    {
        [, $owner] = $this->tenantWithOwner();
        Sanctum::actingAs($owner);

        $this->postJson('/api/v1/sites', ['name' => 'Site 1'])->assertCreated();
        $this->postJson('/api/v1/sites', ['name' => 'Site 2'])
            ->assertStatus(403)
            ->assertJsonPath('errors.0.code', 'plan_limit_reached')
            ->assertJsonPath('meta.max', 1);
    }

    public function test_business_plan_has_no_site_limit(): void
    {
        [, $owner] = $this->tenantWithOwner(plan: 'BUSINESS');
        Sanctum::actingAs($owner);

        foreach (range(1, 5) as $i) {
            $this->postJson('/api/v1/sites', ['name' => "Site $i"])->assertCreated();
        }
    }

    public function test_expired_trial_blocks_writes_but_not_reads_or_payment(): void
    {
        [, $owner] = $this->tenantWithOwner();
        Sanctum::actingAs($owner);

        $this->travel(15)->days();
        $this->artisan('subscriptions:process')->assertSuccessful();

        $this->assertSame('EXPIRED', $this->subscription()->status);
        $this->postJson('/api/v1/sites', ['name' => 'X'])->assertStatus(402)->assertJsonPath('errors.0.code', 'subscription_inactive');
        $this->getJson('/api/v1/sites')->assertOk();
        $this->getJson('/api/v1/subscription')->assertOk()->assertJsonPath('data.subscription.status', 'EXPIRED');

        // Reprendre un abonnement depuis EXPIRED passe en attente de premier paiement.
        $this->postJson('/api/v1/subscription/checkout', ['plan_code' => 'PRO', 'billing_cycle' => 'MONTHLY', 'provider' => 'mock'])->assertCreated();
        $this->assertSame('PENDING', $this->subscription()->status);
    }

    public function test_trial_is_still_valid_on_day_13(): void
    {
        [, $owner] = $this->tenantWithOwner();
        Sanctum::actingAs($owner);

        $this->travel(13)->days();
        $this->artisan('subscriptions:process')->assertSuccessful();

        $this->assertSame('TRIAL', $this->subscription()->status);
        $this->postJson('/api/v1/sites', ['name' => 'X'])->assertCreated();
    }

    public function test_due_subscription_gets_a_grace_period_then_expires(): void
    {
        [, $owner] = $this->tenantWithOwner();
        Sanctum::actingAs($owner);

        $this->subscription()->update(['status' => 'ACTIVE', 'billing_cycle' => 'MONTHLY', 'current_period_start' => now()->subMonth(), 'current_period_end' => now()->subHour()]);

        $this->artisan('subscriptions:process')->assertSuccessful();
        $subscription = $this->subscription();
        $this->assertSame('PAST_DUE', $subscription->status);
        $this->assertSame($subscription->current_period_end->copy()->addDays(3)->timestamp, $subscription->grace_ends_at->timestamp);

        // Pendant la grâce, l'accès est conservé.
        $this->postJson('/api/v1/sites', ['name' => 'Pendant la grâce'])->assertCreated();

        $this->travel(4)->days();
        $this->artisan('subscriptions:process')->assertSuccessful();
        $this->assertSame('EXPIRED', $this->subscription()->status);
        $this->postJson('/api/v1/sites', ['name' => 'Après'])->assertStatus(402);
    }

    public function test_cancel_takes_effect_at_period_end(): void
    {
        [, $owner] = $this->tenantWithOwner();
        Sanctum::actingAs($owner);

        $this->subscription()->update(['status' => 'ACTIVE', 'billing_cycle' => 'MONTHLY', 'current_period_start' => now(), 'current_period_end' => now()->addDays(10)]);

        $this->postJson('/api/v1/subscription/cancel')->assertOk()->assertJsonPath('data.cancel_at_period_end', true)->assertJsonPath('data.status', 'ACTIVE');

        $this->travel(11)->days();
        $this->artisan('subscriptions:process')->assertSuccessful();
        $this->assertSame('CANCELLED', $this->subscription()->status);
    }

    public function test_cancelling_a_trial_is_immediate(): void
    {
        [, $owner] = $this->tenantWithOwner();
        Sanctum::actingAs($owner);

        $this->postJson('/api/v1/subscription/cancel')->assertOk()->assertJsonPath('data.status', 'CANCELLED');
        $this->postJson('/api/v1/subscription/cancel')->assertStatus(409);
    }

    public function test_suspended_subscription_blocks_writes(): void
    {
        [, $owner] = $this->tenantWithOwner();
        Sanctum::actingAs($owner);
        $this->subscription()->update(['status' => 'SUSPENDED']);

        $this->postJson('/api/v1/sites', ['name' => 'X'])->assertStatus(402);
        $this->getJson('/api/v1/sites')->assertOk();
    }

    public function test_process_command_is_scheduled(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('subscriptions:process')->assertSuccessful();
    }
}
