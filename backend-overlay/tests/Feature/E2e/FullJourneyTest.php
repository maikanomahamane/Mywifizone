<?php

namespace Tests\Feature\E2e;

use App\Domain\Payments\Signature\HmacSignatureVerifier;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Parcours bout en bout, UNIQUEMENT par l'API HTTP (aucune fabrique de données) :
 * inscription -> connexion -> site -> routeur -> enrôlement de l'agent -> profil -> lot de tickets -> confirmation par l'agent
 * -> vente -> reports -> export CSV -> achat direct sur le portail (webhook signé) -> code livré -> agent d'équipe isolé.
 * Le routeur et le fournisseur de paiement sont simulés (agent = appels HTTP, paiement = fournisseur « mock » signé).
 */
class FullJourneyTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    private function agentPollsAndConfirms(string $agentToken): array
    {
        $codes = [];
        foreach ($this->withToken($agentToken)->postJson('/api/v1/agent/poll')->assertOk()->json('data') as $c) {
            $created = $c['type'] === 'CREATE_VOUCHER' ? array_column($c['payload']['vouchers'], 'code') : [];
            $codes = array_merge($codes, $created);
            $this->withToken($agentToken)->postJson("/api/v1/agent/commands/{$c['id']}/result", ['success' => true, 'result' => $created === [] ? [] : ['created' => $created]])->assertOk();
        }

        return $codes;
    }

    private function webhook(string $reference, int $amount): void
    {
        $body = json_encode(['event_id' => (string) Str::uuid(), 'type' => 'payment.succeeded', 'reference' => $reference, 'amount' => $amount, 'currency' => 'XOF']);
        $ts = time();
        $this->call('POST', '/api/v1/payments/webhooks/mock', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X-MOCK-TIMESTAMP' => (string) $ts,
            'HTTP_X-MOCK-SIGNATURE' => (new HmacSignatureVerifier)->sign('test-secret', $ts, $body),
        ], $body)->assertOk();
    }

    public function test_a_new_owner_can_run_a_wifi_zone_from_signup_to_direct_sale(): void
    {
        // 1. Inscription et connexion.
        $reg = $this->postJson('/api/v1/auth/register', [
            'name' => 'Ousmane', 'business_name' => 'WiFi Zone Maradi', 'phone' => '+227 90 12 34 56', 'password' => 'motdepasse-solide', 'device_name' => 'e2e',
        ])->assertCreated();
        $login = $this->postJson('/api/v1/auth/login', ['identifier' => '+22790123456', 'password' => 'motdepasse-solide', 'device_name' => 'e2e'])->assertOk();
        $owner = $login->json('data.tokens.access_token');
        $this->assertNotEmpty($reg->json('data.tokens.refresh_token'));

        // Le portail et l'export demandent la formule PRO : on la fixe directement (le paiement d'abonnement est couvert ailleurs).
        $tenant = Tenant::firstOrFail();
        Subscription::withoutGlobalScopes()->where('tenant_id', $tenant->getKey())->update(['plan_id' => SubscriptionPlan::where('code', 'PRO')->value('id')]);

        // 2. Réseau : site, routeur, enrôlement de l'agent (jeton affiché une seule fois).
        $site = $this->withToken($owner)->postJson('/api/v1/sites', ['name' => 'Marché central'])->assertCreated()->json('data.id');
        $router = $this->withToken($owner)->postJson('/api/v1/routers', ['site_id' => $site, 'name' => 'hAP ax2'])->assertCreated();
        $routerId = $router->json('data.id');
        $agent = $this->postJson('/api/v1/agent/enroll', ['enrollment_code' => $router->json('data.enrollment.code'), 'agent_version' => 'e2e'])->assertCreated();
        $agentToken = $agent->json('data.agent_token');
        $this->assertNotEmpty($agentToken);

        // 3. Offre et stock : profil visible sur le portail, 5 tickets confirmés par l'agent.
        $profile = $this->withToken($owner)->postJson('/api/v1/profiles', [
            'name' => 'Une heure', 'price' => 200, 'currency' => 'XOF', 'duration_minutes' => 60, 'rate_limit' => '2M/2M', 'device_limit' => 1, 'portal_visible' => true,
        ])->assertCreated()->json('data.id');
        $this->withToken($owner)->postJson('/api/v1/voucher-batches', ['router_id' => $routerId, 'profile_id' => $profile, 'quantity' => 5, 'label' => 'Lot 1'])->assertStatus(202);
        $this->assertCount(5, $this->agentPollsAndConfirms($agentToken));
        $this->withToken($owner)->getJson('/api/v1/vouchers?status=AVAILABLE')->assertOk()->assertJsonPath('meta.total', 5);

        // 4. Vente au comptoir (la session de caisse n'est pas obligatoire par défaut : voir CashApiTest).
        $sale = $this->withToken($owner)->postJson('/api/v1/sales', ['router_id' => $routerId, 'profile_id' => $profile, 'payment_method' => 'CASH'])->assertCreated();
        $this->assertSame(200, $sale->json('data.amount'));
        $this->assertNotEmpty($sale->json('data.voucher_code'));

        // 5. Tableau de bord, rapport et export CSV reflètent la vente.
        $this->withToken($owner)->getJson('/api/v1/dashboard')->assertOk();
        $today = now()->toDateString();
        $this->withToken($owner)->getJson("/api/v1/reports/sales?period=daily&from=$today&to=$today")->assertOk();
        $csv = $this->withToken($owner)->get("/api/v1/exports/sales.csv?from=".now()->subDay()->toDateString()."&to=".now()->addDay()->toDateString()."", ['Accept' => 'application/json'])->assertOk()->streamedContent();
        $this->assertStringContainsString(';200;XOF;CASH;', $csv);

        // 6. Portail : activation, offre visible, achat direct, webhook signé, code livré.
        $slug = $this->withToken($owner)->putJson('/api/v1/portal-settings', ['enabled' => true])->assertOk()->json('data.slug');
        $this->getJson("/api/v1/portal/$slug?router=$routerId")->assertOk()->assertJsonPath('data.offers.0.available', true);
        $order = $this->postJson("/api/v1/portal/$slug/orders", ['profile_id' => $profile, 'router_id' => $routerId, 'locale' => 'ha'])->assertCreated();
        $token = $order->json('data.token');
        $this->getJson("/api/v1/portal/orders/$token")->assertOk()->assertJsonPath('data.status', 'PENDING_PAYMENT')->assertJsonPath('data.code', null);

        $reference = \App\Models\PaymentTransaction::withoutGlobalScopes()->latest()->value('provider_reference');
        $this->webhook($reference, 200);
        $done = $this->getJson("/api/v1/portal/orders/$token")->assertOk()->assertJsonPath('data.status', 'FULFILLED');
        $this->assertMatchesRegularExpression('/^[A-HJKMNP-Z2-9]{8}$/', $done->json('data.code'));

        // 7. Un agent d'équipe ne voit que ses ventes et ne gère pas le portail.
        $staff = $this->withToken($owner)->postJson('/api/v1/agents', ['name' => 'Aïcha', 'phone' => '+22790000001', 'password' => 'motdepasse-solide'])->assertCreated();
        $agentLogin = $this->postJson('/api/v1/auth/login', ['identifier' => '+22790000001', 'password' => 'motdepasse-solide', 'device_name' => 'e2e'])->assertOk()->json('data.tokens.access_token');
        $this->withToken($agentLogin)->getJson('/api/v1/sales')->assertOk()->assertJsonPath('meta.total', 0);
        $this->withToken($agentLogin)->putJson('/api/v1/portal-settings', ['enabled' => false])->assertStatus(403);
        $this->assertNotEmpty($staff->json('data.id'));

        // 8. Les ventes du propriétaire : comptoir + portail.
        $this->withToken($owner)->getJson('/api/v1/sales')->assertOk()->assertJsonPath('meta.total', 2);
    }
}
