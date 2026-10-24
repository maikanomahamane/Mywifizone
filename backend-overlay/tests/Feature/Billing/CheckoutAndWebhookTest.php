<?php

namespace Tests\Feature\Billing;

use App\Domain\Payments\Signature\HmacSignatureVerifier;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\PaymentTransaction;
use App\Models\Promotion;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/** Le fournisseur "mock" est fictif : les webhooks sont signés avec le secret de test (phpunit.xml). */
class CheckoutAndWebhookTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private const SECRET = 'test-secret';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    private function checkout(array $overrides = [], string $key = 'key-1')
    {
        return $this->postJson(
            '/api/v1/subscription/checkout',
            array_merge(['plan_code' => 'PRO', 'billing_cycle' => 'MONTHLY', 'provider' => 'mock'], $overrides),
            ['Idempotency-Key' => $key],
        );
    }

    private function webhook(string $reference, string $type, int $amount, string $eventId = '', ?int $timestamp = null, string $secret = self::SECRET, string $currency = 'XOF')
    {
        $body = json_encode([
            'event_id' => $eventId ?: (string) Str::uuid(),
            'type' => $type,
            'reference' => $reference,
            'amount' => $amount,
            'currency' => $currency,
        ]);
        $timestamp ??= time();
        $signature = (new HmacSignatureVerifier)->sign($secret, $timestamp, $body);

        return $this->call('POST', '/api/v1/payments/webhooks/mock', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_MOCK_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_MOCK_SIGNATURE' => $signature,
        ], $body);
    }

    /** @return array{0: string, 1: array} référence fournisseur et données de la réponse */
    private function startCheckout(): array
    {
        [, $owner] = $this->tenantWithOwner();
        Sanctum::actingAs($owner);

        $data = $this->checkout()->assertCreated()->json('data');

        return [$data['payment']['reference'], $data];
    }

    public function test_checkout_creates_open_invoice_and_pending_transaction(): void
    {
        [$reference, $data] = $this->startCheckout();

        $this->assertStringStartsWith('mock_', $reference);
        $this->assertSame('OPEN', $data['invoice']['status']);
        $this->assertSame(5000, $data['invoice']['total']);
        $this->assertSame('XOF', $data['invoice']['currency']);
        $this->assertSame('PENDING', $data['payment']['status']);
    }

    public function test_successful_webhook_pays_invoice_and_activates_subscription(): void
    {
        [$reference] = $this->startCheckout();

        $this->webhook($reference, 'payment.succeeded', 5000)->assertOk()->assertJsonPath('data.outcome', 'processed');

        $invoice = Invoice::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('PAID', $invoice->status);
        $this->assertNotNull($invoice->receipt_number);

        $subscription = Subscription::withoutGlobalScopes()->with('plan')->firstOrFail();
        $this->assertSame('ACTIVE', $subscription->status);
        $this->assertSame('PRO', $subscription->plan->code);
        $this->assertEqualsWithDelta(now()->addMonthNoOverflow()->timestamp, $subscription->current_period_end->timestamp, 120);
        $this->assertSame('SUCCEEDED', PaymentTransaction::withoutGlobalScopes()->firstOrFail()->status);
    }

    public function test_replayed_webhook_is_idempotent(): void
    {
        [$reference] = $this->startCheckout();

        $this->webhook($reference, 'payment.succeeded', 5000, 'evt-1')->assertJsonPath('data.outcome', 'processed');
        $end = Subscription::withoutGlobalScopes()->firstOrFail()->current_period_end;

        $this->webhook($reference, 'payment.succeeded', 5000, 'evt-1')->assertOk()->assertJsonPath('data.outcome', 'duplicate');

        $this->assertEquals($end, Subscription::withoutGlobalScopes()->firstOrFail()->current_period_end);
        $this->assertSame(1, AuditLog::where('action', 'subscription.activated')->count());
    }

    public function test_same_payment_reported_under_a_new_event_id_does_not_extend_twice(): void
    {
        [$reference] = $this->startCheckout();

        $this->webhook($reference, 'payment.succeeded', 5000, 'evt-1')->assertOk();
        $this->webhook($reference, 'payment.succeeded', 5000, 'evt-2')->assertOk()->assertJsonPath('data.outcome', 'duplicate');

        $this->assertSame(1, AuditLog::where('action', 'subscription.activated')->count());
    }

    public function test_forged_signature_is_rejected_and_nothing_changes(): void
    {
        [$reference] = $this->startCheckout();

        $this->webhook($reference, 'payment.succeeded', 5000, secret: 'wrong-secret')
            ->assertStatus(401)->assertJsonPath('errors.0.code', 'invalid_signature');

        $this->assertSame('OPEN', Invoice::withoutGlobalScopes()->firstOrFail()->status);
        $this->assertSame('TRIAL', Subscription::withoutGlobalScopes()->firstOrFail()->status);
    }

    public function test_stale_timestamp_is_rejected_as_replay(): void
    {
        [$reference] = $this->startCheckout();

        $this->webhook($reference, 'payment.succeeded', 5000, timestamp: time() - 1000)->assertStatus(401);
        $this->assertSame('OPEN', Invoice::withoutGlobalScopes()->firstOrFail()->status);
    }

    public function test_amount_mismatch_never_activates(): void
    {
        [$reference] = $this->startCheckout();

        $this->webhook($reference, 'payment.succeeded', 1)->assertOk()->assertJsonPath('data.outcome', 'rejected');
        $this->webhook($reference, 'payment.succeeded', 5000, currency: 'EUR')->assertOk()->assertJsonPath('data.outcome', 'rejected');

        $this->assertSame('OPEN', Invoice::withoutGlobalScopes()->firstOrFail()->status);
        $this->assertSame('PENDING', PaymentTransaction::withoutGlobalScopes()->firstOrFail()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'payment.amount_mismatch']);
    }

    public function test_failed_payment_leaves_invoice_open_and_failed_is_terminal(): void
    {
        [$reference] = $this->startCheckout();

        $this->webhook($reference, 'payment.failed', 5000)->assertJsonPath('data.outcome', 'processed');
        $this->assertSame('FAILED', PaymentTransaction::withoutGlobalScopes()->firstOrFail()->status);
        $this->assertSame('OPEN', Invoice::withoutGlobalScopes()->firstOrFail()->status);

        // Un succès tardif après un échec définitif est ignoré (transition interdite).
        $this->webhook($reference, 'payment.succeeded', 5000)->assertJsonPath('data.outcome', 'ignored');
        $this->assertSame('OPEN', Invoice::withoutGlobalScopes()->firstOrFail()->status);
    }

    public function test_late_success_after_expiry_is_honoured(): void
    {
        [$reference] = $this->startCheckout();
        PaymentTransaction::withoutGlobalScopes()->update(['status' => 'EXPIRED']);

        $this->webhook($reference, 'payment.succeeded', 5000)->assertJsonPath('data.outcome', 'processed');
        $this->assertSame('PAID', Invoice::withoutGlobalScopes()->firstOrFail()->status);
    }

    public function test_unknown_reference_is_acknowledged_but_ignored(): void
    {
        $this->webhook('mock_does-not-exist', 'payment.succeeded', 5000)->assertOk()->assertJsonPath('data.outcome', 'ignored');
    }

    public function test_unknown_and_unconfigured_providers_return_404(): void
    {
        $this->postJson('/api/v1/payments/webhooks/doesnotexist', [])->assertStatus(404);
        $this->postJson('/api/v1/payments/webhooks/wave', [])->assertStatus(404);
    }

    public function test_same_idempotency_key_returns_same_transaction(): void
    {
        [, $owner] = $this->tenantWithOwner();
        Sanctum::actingAs($owner);

        $first = $this->checkout()->assertCreated()->json('data');
        $second = $this->checkout()->assertOk()->json('data');

        $this->assertSame($first['payment']['id'], $second['payment']['id']);
        $this->assertSame(1, Invoice::withoutGlobalScopes()->count());

        $this->checkout(['plan_code' => 'BUSINESS'])->assertStatus(409)->assertJsonPath('errors.0.code', 'idempotency_key_reused');
    }

    public function test_unconfigured_provider_cannot_be_used_and_invoice_is_voided(): void
    {
        [, $owner] = $this->tenantWithOwner();
        Sanctum::actingAs($owner);

        $this->checkout(['provider' => 'wave'])->assertStatus(422)->assertJsonPath('errors.0.code', 'provider_unavailable');

        $this->assertSame('VOID', Invoice::withoutGlobalScopes()->firstOrFail()->status);
    }

    public function test_yearly_is_unavailable_until_super_admin_sets_a_price(): void
    {
        [, $owner] = $this->tenantWithOwner();
        Sanctum::actingAs($owner);

        $this->checkout(['billing_cycle' => 'YEARLY'])->assertStatus(422)->assertJsonPath('errors.0.code', 'cycle_unavailable');
    }

    public function test_percentage_promotion_reduces_the_total(): void
    {
        Promotion::create(['code' => 'PROMO20', 'type' => 'PERCENTAGE', 'value' => 20]);
        [, $owner] = $this->tenantWithOwner();
        Sanctum::actingAs($owner);

        $invoice = $this->checkout(['promo_code' => 'promo20'])->assertCreated()->json('data.invoice');

        $this->assertSame(5000, $invoice['subtotal']);
        $this->assertSame(1000, $invoice['discount']);
        $this->assertSame(4000, $invoice['total']);
    }

    public function test_fully_discounted_invoice_is_settled_without_payment(): void
    {
        Promotion::create(['code' => 'FREE5000', 'type' => 'FIXED', 'value' => 5000, 'currency' => 'XOF']);
        [, $owner] = $this->tenantWithOwner();
        Sanctum::actingAs($owner);

        $data = $this->checkout(['promo_code' => 'FREE5000'])->assertCreated()->json('data');

        $this->assertNull($data['payment']);
        $this->assertSame('PAID', $data['invoice']['status']);
        $this->assertSame('ACTIVE', Subscription::withoutGlobalScopes()->firstOrFail()->status);
    }

    public function test_expired_or_wrong_kind_of_promotion_is_refused(): void
    {
        Promotion::create(['code' => 'OLD', 'type' => 'PERCENTAGE', 'value' => 50, 'ends_at' => now()->subDay()]);
        Promotion::create(['code' => 'BONUS7', 'type' => 'TRIAL_EXTENSION', 'value' => 7]);
        [, $owner] = $this->tenantWithOwner();
        Sanctum::actingAs($owner);

        $this->checkout(['promo_code' => 'OLD'])->assertStatus(422)->assertJsonPath('errors.0.field', 'promo_code');
        $this->checkout(['promo_code' => 'BONUS7'], 'key-2')->assertStatus(422)->assertJsonPath('errors.0.field', 'promo_code');
        $this->assertSame(0, Invoice::withoutGlobalScopes()->count());
    }

    public function test_invoices_are_isolated_between_tenants(): void
    {
        [, $ownerA] = $this->tenantWithOwner();
        [, $ownerB] = $this->tenantWithOwner();

        Sanctum::actingAs($ownerA);
        $invoiceId = $this->checkout()->assertCreated()->json('data.invoice.id');
        $this->getJson("/api/v1/invoices/$invoiceId")->assertOk();

        Sanctum::actingAs($ownerB);
        $this->getJson("/api/v1/invoices/$invoiceId")->assertStatus(404);
        $this->getJson('/api/v1/invoices')->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_agent_cannot_see_or_manage_the_subscription(): void
    {
        [$tenant] = $this->tenantWithOwner();
        Sanctum::actingAs($this->agentFor($tenant));

        $this->getJson('/api/v1/subscription')->assertStatus(403);
        $this->checkout()->assertStatus(403);
    }
}
