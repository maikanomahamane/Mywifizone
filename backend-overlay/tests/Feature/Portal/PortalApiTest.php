<?php

namespace Tests\Feature\Portal;

use App\Domain\Payments\Signature\HmacSignatureVerifier;
use App\Models\AuditLog;
use App\Models\PaymentTransaction;
use App\Models\PortalOrder;
use App\Models\Sale;
use App\Models\Voucher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesNetwork;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/** Achat direct : paiement (mock signé) -> livraison du voucher. Le fournisseur "mock" est fictif. */
class PortalApiTest extends TestCase
{
    use CreatesNetwork, CreatesTenants, RefreshDatabase;

    private const SECRET = 'test-secret';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    /** @return array{0: \App\Models\Tenant, 1: \App\Models\User, 2: \App\Models\Router, 3: \App\Models\HotspotProfile, 4: string} */
    private function portal(string $plan = 'PRO', int $stock = 2): array
    {
        [$tenant, $owner] = $this->tenantWithOwner(['name' => 'Cyber Maradi'], plan: $plan);
        [$router] = $this->enrolledRouter($tenant);
        $profile = $this->makeProfile($tenant, ['name' => 'Une heure', 'price' => 200, 'portal_visible' => true]);
        for ($i = 0; $i < $stock; $i++) {
            $this->makeVoucher($tenant, $router, $profile);
        }
        Sanctum::actingAs($owner);
        $slug = $this->putJson('/api/v1/portal-settings', ['enabled' => true])->assertOk()->json('data.slug');

        return [$tenant, $owner, $router, $profile, $slug];
    }

    private function webhook(string $reference, string $type, int $amount, string $eventId = '', string $currency = 'XOF')
    {
        $body = json_encode(['event_id' => $eventId ?: (string) Str::uuid(), 'type' => $type, 'reference' => $reference, 'amount' => $amount, 'currency' => $currency]);
        $ts = time();

        return $this->call('POST', '/api/v1/payments/webhooks/mock', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X-MOCK-TIMESTAMP' => (string) $ts,
            'HTTP_X-MOCK-SIGNATURE' => (new HmacSignatureVerifier)->sign(self::SECRET, $ts, $body),
        ], $body);
    }

    private function order(string $slug, $router, $profile, array $extra = [])
    {
        return $this->postJson("/api/v1/portal/$slug/orders", $extra + ['profile_id' => $profile->getKey(), 'router_id' => $router->getKey(), 'locale' => 'fr']);
    }

    public function test_public_portal_lists_offers_with_stock_and_no_secrets(): void
    {
        [, , $router, $profile, $slug] = $this->portal();
        $this->app['auth']->forgetGuards();

        $res = $this->getJson("/api/v1/portal/$slug?router={$router->getKey()}")->assertOk();
        $this->assertSame('Cyber Maradi', $res->json('data.name'));
        $offer = $res->json('data.offers.0');
        $this->assertSame($profile->getKey(), $offer['id']);
        $this->assertSame(200, $offer['price']);
        $this->assertTrue($offer['available']);
        $this->assertStringNotContainsString('tenant_id', $res->getContent());
        $this->assertStringNotContainsString('code', json_encode($offer));
    }

    public function test_hidden_free_vip_and_inactive_profiles_are_not_offered(): void
    {
        [$tenant, , $router, , $slug] = $this->portal();
        $this->makeProfile($tenant, ['name' => 'Caché', 'portal_visible' => false]);
        $this->makeProfile($tenant, ['name' => 'Gratuit', 'price' => 0, 'portal_visible' => true]);
        $this->makeProfile($tenant, ['name' => 'VIP', 'kind' => 'VIP', 'portal_visible' => true]);
        $this->makeProfile($tenant, ['name' => 'Inactif', 'is_active' => false, 'portal_visible' => true]);

        $names = array_column($this->getJson("/api/v1/portal/$slug")->assertOk()->json('data.offers'), 'name');
        $this->assertSame(['Une heure'], $names);
    }

    public function test_unknown_disabled_or_unpaid_portals_are_unavailable(): void
    {
        [$tenant, , , , $slug] = $this->portal();
        $this->getJson('/api/v1/portal/zzzzzzzzzzzz')->assertStatus(404);

        $this->putJson('/api/v1/portal-settings', ['enabled' => false])->assertOk();
        $this->getJson("/api/v1/portal/$slug")->assertStatus(404);

        $this->putJson('/api/v1/portal-settings', ['enabled' => true])->assertOk();
        \App\Models\Subscription::withoutGlobalScopes()->where('tenant_id', $tenant->getKey())->update(['status' => 'EXPIRED']);
        $this->getJson("/api/v1/portal/$slug")->assertStatus(503)->assertJsonPath('errors.0.code', 'portal_unavailable');
    }

    public function test_starter_plan_cannot_enable_the_portal(): void
    {
        [, $owner] = $this->tenantWithOwner(plan: 'STARTER');
        Sanctum::actingAs($owner);
        $this->putJson('/api/v1/portal-settings', ['enabled' => true])->assertStatus(403)->assertJsonPath('errors.0.code', 'feature_unavailable');
    }

    public function test_paid_order_delivers_the_voucher_and_records_a_sale(): void
    {
        [$tenant, , $router, $profile, $slug] = $this->portal();
        $this->app['auth']->forgetGuards();

        $created = $this->order($slug, $router, $profile, ['customer_phone' => '+22790000000'])->assertCreated();
        $token = $created->json('data.token');
        $this->assertSame('PENDING_PAYMENT', $created->json('data.status'));
        $this->assertNull($this->getJson("/api/v1/portal/orders/$token")->json('data.code'), 'pas de code avant paiement');

        $reference = PaymentTransaction::withoutGlobalScopes()->firstOrFail()->provider_reference;
        $this->webhook($reference, 'payment.succeeded', 200)->assertOk()->assertJsonPath('data.outcome', 'processed');

        $status = $this->getJson("/api/v1/portal/orders/$token")->assertOk();
        $this->assertSame('FULFILLED', $status->json('data.status'));
        $code = $status->json('data.code');
        $this->assertNotEmpty($code);

        $voucher = Voucher::withoutGlobalScopes()->where('code', $code)->firstOrFail();
        $this->assertSame('SOLD', $voucher->status);
        $sale = Sale::withoutGlobalScopes()->firstOrFail();
        $this->assertSame(200, $sale->amount);
        $this->assertSame('MOBILE_MONEY', $sale->payment_method);
        $this->assertNull($sale->agent_id);
        $this->assertSame($reference, $sale->provider_transaction_id);
        $this->assertSame($tenant->getKey(), $sale->tenant_id);
    }

    public function test_replayed_webhook_delivers_only_one_voucher(): void
    {
        [, , $router, $profile, $slug] = $this->portal();
        $this->app['auth']->forgetGuards();
        $this->order($slug, $router, $profile)->assertCreated();
        $reference = PaymentTransaction::withoutGlobalScopes()->firstOrFail()->provider_reference;

        $this->webhook($reference, 'payment.succeeded', 200, 'evt-1')->assertJsonPath('data.outcome', 'processed');
        $this->webhook($reference, 'payment.succeeded', 200, 'evt-1')->assertJsonPath('data.outcome', 'duplicate');
        $this->webhook($reference, 'payment.succeeded', 200, 'evt-2')->assertOk();

        $this->assertSame(1, Sale::withoutGlobalScopes()->count());
        $this->assertSame(1, Voucher::withoutGlobalScopes()->where('status', 'SOLD')->count());
    }

    public function test_wrong_amount_or_failed_payment_never_delivers(): void
    {
        [, , $router, $profile, $slug] = $this->portal();
        $this->app['auth']->forgetGuards();
        $token = $this->order($slug, $router, $profile)->json('data.token');
        $reference = PaymentTransaction::withoutGlobalScopes()->firstOrFail()->provider_reference;

        $this->webhook($reference, 'payment.succeeded', 1)->assertJsonPath('data.outcome', 'rejected');
        $this->assertSame('PENDING_PAYMENT', $this->getJson("/api/v1/portal/orders/$token")->json('data.status'));

        $this->webhook($reference, 'payment.failed', 200)->assertOk();
        $this->assertSame('FAILED', $this->getJson("/api/v1/portal/orders/$token")->json('data.status'));
        $this->assertSame(0, Sale::withoutGlobalScopes()->count());
        $this->assertSame(0, Voucher::withoutGlobalScopes()->where('status', 'SOLD')->count());
    }

    public function test_unsigned_webhook_is_rejected_and_nothing_is_delivered(): void
    {
        [, , $router, $profile, $slug] = $this->portal();
        $this->app['auth']->forgetGuards();
        $this->order($slug, $router, $profile)->assertCreated();
        $reference = PaymentTransaction::withoutGlobalScopes()->firstOrFail()->provider_reference;

        $this->postJson('/api/v1/payments/webhooks/mock', ['event_id' => 'x', 'type' => 'payment.succeeded', 'reference' => $reference, 'amount' => 200, 'currency' => 'XOF'])->assertStatus(401);
        $this->assertSame(0, Sale::withoutGlobalScopes()->count());
    }

    public function test_paid_but_out_of_stock_needs_attention_and_is_audited(): void
    {
        [$tenant, , $router, $profile, $slug] = $this->portal(stock: 1);
        $this->app['auth']->forgetGuards();
        $token = $this->order($slug, $router, $profile)->json('data.token');
        // Un agent vend le dernier ticket entre la commande et le paiement.
        Voucher::withoutGlobalScopes()->where('tenant_id', $tenant->getKey())->update(['status' => 'SOLD']);

        $reference = PaymentTransaction::withoutGlobalScopes()->firstOrFail()->provider_reference;
        $this->webhook($reference, 'payment.succeeded', 200)->assertOk();

        $status = $this->getJson("/api/v1/portal/orders/$token")->json('data');
        $this->assertSame('NEEDS_ATTENTION', $status['status']);
        $this->assertNull($status['code']);
        $this->assertNotEmpty($status['reference']);
        $this->assertSame(1, AuditLog::where('action', 'portal.no_stock_after_payment')->count());
    }

    public function test_sold_out_offers_cannot_be_ordered(): void
    {
        [, , $router, $profile, $slug] = $this->portal(stock: 0);
        $this->app['auth']->forgetGuards();
        $this->order($slug, $router, $profile)->assertStatus(409)->assertJsonPath('errors.0.code', 'sold_out');
        $this->assertSame(0, PortalOrder::withoutGlobalScopes()->count());
    }

    public function test_order_validation_and_cross_tenant_ids_are_refused(): void
    {
        [, , $router, $profile, $slug] = $this->portal();
        [$otherTenant] = $this->tenantWithOwner(plan: 'PRO');
        [$otherRouter] = $this->enrolledRouter($otherTenant);
        $otherProfile = $this->makeProfile($otherTenant, ['portal_visible' => true]);
        $this->makeVoucher($otherTenant, $otherRouter, $otherProfile);
        $this->app['auth']->forgetGuards();

        $this->order($slug, $otherRouter, $profile)->assertStatus(422)->assertJsonPath('errors.0.code', 'offer_unavailable');
        $this->order($slug, $router, $otherProfile)->assertStatus(422)->assertJsonPath('errors.0.code', 'offer_unavailable');
        $this->order($slug, $router, $profile, ['customer_phone' => 'abc'])->assertStatus(422);
        $this->order($slug, $router, $profile, ['locale' => 'zz'])->assertStatus(422);
        $this->order($slug, $router, $profile, ['client_ref' => 'a b!'])->assertStatus(422);
        $this->postJson("/api/v1/portal/$slug/orders", [])->assertStatus(422);
    }

    public function test_client_ref_replay_returns_the_same_order(): void
    {
        [, , $router, $profile, $slug] = $this->portal();
        $this->app['auth']->forgetGuards();
        $a = $this->order($slug, $router, $profile, ['client_ref' => 'web-1'])->assertCreated()->json('data.token');
        $b = $this->order($slug, $router, $profile, ['client_ref' => 'web-1'])->assertCreated()->json('data.token');

        $this->assertSame($a, $b);
        $this->assertSame(1, PortalOrder::withoutGlobalScopes()->count());
        $this->assertSame(1, PaymentTransaction::withoutGlobalScopes()->count());
    }

    public function test_order_status_needs_the_secret_token_and_hides_it_from_owner_lists(): void
    {
        [, $owner, $router, $profile, $slug] = $this->portal();
        $this->app['auth']->forgetGuards();
        $token = $this->order($slug, $router, $profile)->json('data.token');

        $this->getJson('/api/v1/portal/orders/'.str_repeat('a', 32))->assertStatus(404);
        $this->getJson('/api/v1/portal/orders/short')->assertStatus(404);

        Sanctum::actingAs($owner);
        $list = $this->getJson('/api/v1/portal-orders')->assertOk();
        $this->assertSame(1, $list->json('meta.total'));
        $this->assertStringNotContainsString($token, $list->getContent());
    }

    public function test_code_is_hidden_after_the_reveal_window(): void
    {
        [, , $router, $profile, $slug] = $this->portal();
        $this->app['auth']->forgetGuards();
        $token = $this->order($slug, $router, $profile)->json('data.token');
        $reference = PaymentTransaction::withoutGlobalScopes()->firstOrFail()->provider_reference;
        $this->webhook($reference, 'payment.succeeded', 200)->assertOk();
        $this->assertNotNull($this->getJson("/api/v1/portal/orders/$token")->json('data.code'));

        PortalOrder::withoutGlobalScopes()->update(['fulfilled_at' => now()->subHours(48)]);
        $this->assertNull($this->getJson("/api/v1/portal/orders/$token")->json('data.code'));
    }

    public function test_page_is_responsive_html_without_secrets_and_with_strict_headers(): void
    {
        [$tenant, $owner, , , $slug] = $this->portal();
        $this->putJson('/api/v1/branding', ['display_name' => '<script>alert(1)</script>', 'slogan' => '"><img src=x>', 'primary_color' => '#112233'])->assertOk();
        $this->app['auth']->forgetGuards();

        $res = $this->get("/api/v1/portal/$slug/page?lang=ar")->assertOk();
        $html = $res->getContent();
        $this->assertStringContainsString('dir="rtl"', $html);
        $this->assertStringContainsString('name="viewport"', $html);
        $this->assertStringContainsString('--c:#112233', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('<img src=x>', $html);
        $this->assertStringContainsString("default-src 'none'", $res->headers->get('Content-Security-Policy'));
        $this->assertSame('no-store', $res->headers->get('Cache-Control'));
        $this->assertStringNotContainsString($tenant->getKey(), $html);
        $this->assertStringNotContainsString(self::SECRET, $html);
    }

    public function test_regenerating_the_slug_kills_the_old_link(): void
    {
        [, , , , $slug] = $this->portal();
        $new = $this->putJson('/api/v1/portal-settings', ['regenerate_slug' => true])->assertOk()->json('data.slug');
        $this->assertNotSame($slug, $new);
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/v1/portal/$slug")->assertStatus(404);
        $this->getJson("/api/v1/portal/$new")->assertOk();
    }

    public function test_external_links_are_https_only_and_store_no_credentials(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner(plan: 'PRO');
        Sanctum::actingAs($owner);

        $ok = $this->postJson('/api/v1/external-links', ['kind' => 'STARLINK', 'name' => 'Starlink', 'url' => 'https://www.starlink.com/account'])->assertCreated();
        foreach (['http://www.starlink.com', 'https://user:pass@www.starlink.com', 'javascript:alert(1)', 'https://localhost', 'https://a b.com', 'ftp://x.com'] as $bad) {
            $this->postJson('/api/v1/external-links', ['name' => 'X', 'url' => $bad])->assertStatus(422);
        }
        $this->putJson('/api/v1/external-links/'.$ok->json('data.id'), ['name' => 'Starlink officiel'])->assertOk();
        $this->assertCount(1, $this->getJson('/api/v1/external-links')->json('data'));

        Sanctum::actingAs($this->agentFor($tenant));
        $this->getJson('/api/v1/external-links')->assertOk();
        $this->postJson('/api/v1/external-links', ['name' => 'X', 'url' => 'https://x.com'])->assertStatus(403);

        [, $other] = $this->tenantWithOwner(plan: 'PRO');
        Sanctum::actingAs($other);
        $this->assertCount(0, $this->getJson('/api/v1/external-links')->json('data'));
        $this->deleteJson('/api/v1/external-links/'.$ok->json('data.id'))->assertStatus(404);
    }

    public function test_agents_cannot_manage_the_portal(): void
    {
        [$tenant] = $this->portal();
        Sanctum::actingAs($this->agentFor($tenant));
        $this->getJson('/api/v1/portal-settings')->assertStatus(403);
        $this->putJson('/api/v1/portal-settings', ['enabled' => false])->assertStatus(403);
    }
}
