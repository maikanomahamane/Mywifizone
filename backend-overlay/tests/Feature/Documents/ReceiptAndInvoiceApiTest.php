<?php

namespace Tests\Feature\Documents;

use App\Infrastructure\Documents\PdfEngine;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesNetwork;
use Tests\Concerns\CreatesTenants;
use Tests\Support\FakePdfEngine;
use Tests\TestCase;

class ReceiptAndInvoiceApiTest extends TestCase
{
    use CreatesNetwork, CreatesTenants, RefreshDatabase;

    private FakePdfEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->engine = new FakePdfEngine;
        $this->app->instance(PdfEngine::class, $this->engine);
    }

    /** Vend un ticket via l'API et renvoie [vente, code]. */
    private function sell($agent, $router, $profile, string $method = 'CASH', ?string $ref = null): array
    {
        Sanctum::actingAs($agent);
        $res = $this->postJson('/api/v1/sales', ['router_id' => $router->id, 'profile_id' => $profile->id, 'payment_method' => $method, 'provider_transaction_id' => $ref])->assertCreated();

        return [$res->json('data'), $res->json('data.voucher_code')];
    }

    public function test_receipt_masks_the_ticket_code_and_shows_the_amount(): void
    {
        [$tenant] = $this->tenantWithOwner();
        $agent = $this->agentFor($tenant);
        [$router] = $this->enrolledRouter($tenant);
        $profile = $this->makeProfile($tenant, ['name' => '1 heure', 'price' => 12500]);
        $this->makeVoucher($tenant, $router, $profile);
        [$sale, $code] = $this->sell($agent, $router, $profile, 'MOBILE_MONEY', 'MM-777');

        $res = $this->get("/api/v1/sales/{$sale['id']}/receipt?format=thermal", ['Accept' => 'application/json'])->assertOk();

        $this->assertSame('application/pdf', $res->headers->get('Content-Type'));
        $html = $this->engine->html;
        $this->assertStringContainsString("12\u{00A0}500\u{00A0}FCFA", $html);
        $this->assertStringContainsString('MM-777', $html);
        $this->assertStringContainsString('Mobile money', $html);
        $this->assertStringNotContainsString($code, $html, 'le code complet ne figure pas sur le reçu');
        $this->assertStringContainsString(substr($code, -3), $html);
        $this->assertSame(58, $this->engine->page['page_w']);
    }

    public function test_receipt_access_rules(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner();
        $a = $this->agentFor($tenant);
        $b = $this->agentFor($tenant);
        [$router] = $this->enrolledRouter($tenant);
        $profile = $this->makeProfile($tenant);
        $this->makeVoucher($tenant, $router, $profile);
        [$sale] = $this->sell($a, $router, $profile);

        Sanctum::actingAs($b);
        $this->getJson("/api/v1/sales/{$sale['id']}/receipt")->assertStatus(403);

        Sanctum::actingAs($owner);
        $this->get("/api/v1/sales/{$sale['id']}/receipt?format=a5&locale=en", ['Accept' => 'application/json'])->assertOk();
        $this->assertSame('en', $this->engine->locale);
        $this->assertStringContainsString('Receipt', $this->engine->html);

        [, $intruder] = $this->tenantWithOwner();
        Sanctum::actingAs($intruder);
        $this->getJson("/api/v1/sales/{$sale['id']}/receipt")->assertStatus(404);
    }

    public function test_voided_sale_receipt_says_so_and_unknown_format_is_rejected(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner();
        $agent = $this->agentFor($tenant);
        [$router] = $this->enrolledRouter($tenant);
        $profile = $this->makeProfile($tenant);
        $this->makeVoucher($tenant, $router, $profile);
        [$sale] = $this->sell($agent, $router, $profile);

        Sanctum::actingAs($owner);
        $this->postJson("/api/v1/sales/{$sale['id']}/void", ['reason' => 'erreur'])->assertOk();
        $this->get("/api/v1/sales/{$sale['id']}/receipt", ['Accept' => 'application/json'])->assertOk();
        $this->assertStringContainsString('ANNULÉ', $this->engine->html);

        $this->getJson("/api/v1/sales/{$sale['id']}/receipt?format=poster")->assertStatus(422);
    }

    private function invoiceFor($tenant): Invoice
    {
        return $this->asTenant($tenant, function () {
            $sub = Subscription::firstOrFail();
            $plan = SubscriptionPlan::where('code', 'PRO')->firstOrFail();

            return Invoice::create([
                'subscription_id' => $sub->getKey(), 'plan_id' => $plan->getKey(), 'number' => 'INV-20261018-ABC123', 'status' => 'PAID',
                'billing_cycle' => 'MONTHLY', 'currency' => 'XOF', 'subtotal' => 5000, 'discount' => 500, 'total' => 4500,
                'period_start' => now(), 'period_end' => now()->addMonth(), 'paid_at' => now(),
            ]);
        });
    }

    public function test_invoice_pdf_for_owner_only_and_tenant_isolated(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner(['name' => 'Cyber <Maradi>']);
        $invoice = $this->invoiceFor($tenant);

        Sanctum::actingAs($owner);
        $res = $this->get("/api/v1/invoices/{$invoice->id}/pdf?locale=fr", ['Accept' => 'application/json'])->assertOk();
        $this->assertStringContainsString('facture-INV-20261018-ABC123.pdf', $res->headers->get('Content-Disposition'));
        $html = $this->engine->html;
        $this->assertStringContainsString("4\u{00A0}500\u{00A0}FCFA", $html);
        $this->assertStringContainsString('INV-20261018-ABC123', $html);
        $this->assertStringContainsString('Cyber &lt;Maradi&gt;', $html);

        $agent = $this->agentFor($tenant);
        Sanctum::actingAs($agent);
        $this->getJson("/api/v1/invoices/{$invoice->id}/pdf")->assertStatus(403);

        [, $intruder] = $this->tenantWithOwner();
        Sanctum::actingAs($intruder);
        $this->getJson("/api/v1/invoices/{$invoice->id}/pdf")->assertStatus(404);
    }
}
