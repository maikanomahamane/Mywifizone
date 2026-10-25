<?php

namespace Tests\Feature\Network;

use App\Models\Router;
use App\Models\Voucher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesNetwork;
use Tests\Concerns\CreatesTenants;
use Tests\Support\FakeRouterOs;
use Tests\TestCase;

class DirectRouterTest extends TestCase
{
    use CreatesNetwork, CreatesTenants, RefreshDatabase;

    private const ADMIN_PASSWORD = 'admin-secret-ZZ9';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    private function body($site, array $over = []): array
    {
        return $over + [
            'site_id' => $site->getKey(), 'name' => 'Routeur boutique', 'connection_mode' => 'REST',
            'host' => '41.203.1.10', 'port' => 443, 'username' => 'admin', 'password' => self::ADMIN_PASSWORD,
        ];
    }

    private function connected(): array
    {
        [$tenant, $owner] = $this->tenantWithOwner(plan: 'PRO');
        $site = $this->makeSite($tenant);
        $ros = (new FakeRouterOs(adminPassword: self::ADMIN_PASSWORD))->install();
        Sanctum::actingAs($owner);
        $id = $this->postJson('/api/v1/routers', $this->body($site))->assertCreated()->json('data.id');

        return [$tenant, $owner, $ros, $id];
    }

    public function test_owner_adds_a_router_with_admin_credentials_and_only_a_dedicated_account_is_kept(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner(plan: 'PRO');
        $site = $this->makeSite($tenant);
        $ros = (new FakeRouterOs(adminPassword: self::ADMIN_PASSWORD))->install();
        Sanctum::actingAs($owner);

        $res = $this->postJson('/api/v1/routers', $this->body($site))->assertCreated();

        $this->assertSame('REST', $res->json('data.connection_mode'));
        $this->assertSame('ONLINE', $res->json('data.status'));
        $this->assertSame('hotspot1', $res->json('data.hotspot_server'));
        $this->assertSame('hAP ax2', $res->json('data.model'));
        $this->assertSame('41.203.1.10', $res->json('data.rest_host'));
        $this->assertArrayNotHasKey('enrollment', $res->json('data'));

        // Le routeur reçoit un groupe et un utilisateur dédiés, aux droits limités.
        $this->assertSame('wifizone', array_values($ros->groups)[0]['name']);
        $this->assertSame('read,write,api,rest-api,test', array_values($ros->groups)[0]['policy']);
        $dedicated = $ros->systemUsers['wifizone'];
        $this->assertSame('wifizone', $dedicated['group']);
        $this->assertNotSame(self::ADMIN_PASSWORD, $dedicated['password']);
        $this->assertGreaterThanOrEqual(32, strlen($dedicated['password']));

        // Le compte dédié a été éprouvé avant d'être conservé.
        $this->assertNotEmpty(array_filter($ros->log, fn ($r) => $r[2] === 'wifizone' && $r[1] === '/system/resource'));

        // Base : le mot de passe admin n'existe nulle part ; le compte dédié est chiffré, jamais renvoyé par l'API.
        $row = (array) DB::table('routers')->where('id', $res->json('data.id'))->first();
        $this->assertStringNotContainsString(self::ADMIN_PASSWORD, json_encode($row));
        $this->assertNotSame($dedicated['password'], $row['rest_password']);
        $this->assertSame($dedicated['password'], Router::withoutGlobalScopes()->find($res->json('data.id'))->rest_password);
        $this->assertStringNotContainsString($dedicated['password'], $res->getContent());
        $this->assertStringNotContainsString(self::ADMIN_PASSWORD, $res->getContent());
        $this->assertStringNotContainsString('rest_password', $this->getJson('/api/v1/routers')->getContent());
        $this->assertDatabaseMissing('audit_logs', ['metadata' => null, 'action' => 'never']);
        $this->assertStringNotContainsString(self::ADMIN_PASSWORD, json_encode(DB::table('audit_logs')->get()));
    }

    public function test_wrong_password_is_reported_and_nothing_is_kept(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner(plan: 'PRO');
        $site = $this->makeSite($tenant);
        (new FakeRouterOs(adminPassword: self::ADMIN_PASSWORD))->install();
        Sanctum::actingAs($owner);

        $this->postJson('/api/v1/routers', $this->body($site, ['password' => 'mauvais']))
            ->assertStatus(422)->assertJsonPath('errors.0.code', 'router_auth_failed');

        $this->assertSame(0, Router::count());
    }

    public function test_unreachable_router_and_missing_hotspot_have_clear_errors(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner(plan: 'PRO');
        $site = $this->makeSite($tenant);
        $ros = (new FakeRouterOs(adminPassword: self::ADMIN_PASSWORD))->install();
        Sanctum::actingAs($owner);

        $ros->down = true;
        $this->postJson('/api/v1/routers', $this->body($site))->assertStatus(422)->assertJsonPath('errors.0.code', 'router_unreachable');

        $ros->down = false;
        $ros->servers = [];
        $this->postJson('/api/v1/routers', $this->body($site))->assertStatus(422)->assertJsonPath('errors.0.code', 'hotspot_not_configured');

        $ros->servers = [['.id' => '*1', 'name' => 'hotspot1']];
        $this->postJson('/api/v1/routers', $this->body($site, ['hotspot_server' => 'autre']))->assertStatus(422)->assertJsonPath('errors.0.code', 'hotspot_not_found');

        $this->assertSame(0, Router::count());
    }

    public function test_server_never_connects_to_internal_or_reserved_addresses(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner(plan: 'PRO');
        $site = $this->makeSite($tenant);
        Http::fake();
        Sanctum::actingAs($owner);

        foreach (['127.0.0.1', '10.0.0.5', '192.168.88.1', '169.254.169.254', '100.64.0.1', 'localhost', 'http://1.2.3.4', '1.2.3.4:443'] as $host) {
            $this->postJson('/api/v1/routers', $this->body($site, ['host' => $host]))->assertStatus(422);
        }
        $this->postJson('/api/v1/routers', $this->body($site, ['port' => 5432]))->assertStatus(422)->assertJsonPath('errors.0.code', 'router_target_forbidden');

        Http::assertNothingSent();
        $this->assertSame(0, Router::count());
    }

    public function test_credentials_are_required_for_a_direct_router_and_agents_cannot_add_routers(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner(plan: 'PRO');
        $site = $this->makeSite($tenant);
        Sanctum::actingAs($owner);

        $this->postJson('/api/v1/routers', ['site_id' => $site->getKey(), 'name' => 'R', 'connection_mode' => 'REST'])->assertStatus(422);

        Sanctum::actingAs($this->agentFor($tenant));
        $this->postJson('/api/v1/routers', $this->body($site))->assertStatus(403);
    }

    public function test_plan_limit_applies_before_any_network_call(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner(plan: 'STARTER'); // 1 routeur
        $site = $this->makeSite($tenant);
        $this->makeRouter($tenant, $site);
        Http::fake();
        Sanctum::actingAs($owner);

        $this->postJson('/api/v1/routers', $this->body($site))->assertStatus(403)->assertJsonPath('errors.0.code', 'plan_limit_reached');
        Http::assertNothingSent();
    }

    public function test_generating_tickets_creates_hotspot_users_on_the_router_through_the_dedicated_account(): void
    {
        [$tenant, $owner, $ros, $routerId] = $this->connected();
        $profile = $this->makeProfile($tenant);

        $batch = $this->postJson('/api/v1/voucher-batches', ['router_id' => $routerId, 'profile_id' => $profile->id, 'quantity' => 3])
            ->assertStatus(202)->json('data.id');

        $created = $ros->requests('PUT', '/ip/hotspot/user');
        $this->assertCount(3, array_filter($created, fn ($r) => ! str_contains($r[1], 'profile')));
        foreach ($created as $r) {
            if (str_contains($r[1], 'profile')) {
                continue;
            }
            $this->assertSame('wifizone', $r[2]);                       // jamais le compte admin
            $this->assertSame($r[3]['name'], $r[3]['password']);
            $this->assertStringStartsWith('wz:', $r[3]['comment']);
        }
        $this->assertSame(3, Voucher::withoutGlobalScopes()->where('batch_id', $batch)->where('status', 'AVAILABLE')->where('sync_status', 'SYNCED')->count());
    }

    public function test_an_unreachable_router_leaves_the_commands_to_retry_then_recovers(): void
    {
        [$tenant, $owner, $ros, $routerId] = $this->connected();
        $profile = $this->makeProfile($tenant);
        $ros->down = true;

        $batch = $this->postJson('/api/v1/voucher-batches', ['router_id' => $routerId, 'profile_id' => $profile->id, 'quantity' => 2])
            ->assertStatus(202)->json('data.id');

        $this->assertSame(0, Voucher::withoutGlobalScopes()->where('batch_id', $batch)->where('status', 'AVAILABLE')->count());
        $this->assertNotNull(Router::withoutGlobalScopes()->find($routerId)->rest_error);

        // Le routeur revient : la reprise planifiée rejoue les commandes dès que le délai d'attente est passé.
        $ros->down = false;
        $this->travel(2)->minutes();
        Artisan::call('routers:poll-rest');

        $this->assertSame(2, Voucher::withoutGlobalScopes()->where('batch_id', $batch)->where('status', 'AVAILABLE')->count());
        $this->assertNull(Router::withoutGlobalScopes()->find($routerId)->rest_error);
    }

    public function test_scheduled_poll_reads_health_and_active_sessions(): void
    {
        [$tenant, , $ros, $routerId] = $this->connected();
        $profile = $this->makeProfile($tenant);
        $router = Router::withoutGlobalScopes()->find($routerId);
        $voucher = $this->makeVoucher($tenant, $router, $profile);
        $ros->active = ['*A' => ['.id' => '*A', 'user' => $voucher->code, 'address' => '10.5.50.12', 'mac-address' => 'AA:BB:CC:00:11:22', 'uptime' => '5m', 'bytes-in' => '1000', 'bytes-out' => '2000']];

        $this->travel(2)->minutes();
        Artisan::call('routers:poll-rest');

        $router->refresh();
        $this->assertSame(1, $router->clients_count);
        $this->assertSame('ONLINE', $router->status);
        $this->assertSame('ACTIVE', Voucher::withoutGlobalScopes()->find($voucher->id)->status);
    }

    public function test_test_endpoint_and_reconnect_with_new_admin_password(): void
    {
        [$tenant, $owner, $ros, $routerId] = $this->connected();

        $this->postJson("/api/v1/routers/$routerId/test")->assertOk()->assertJsonPath('data.ok', true);

        // Le compte dédié est supprimé côté routeur : le test le dit, puis « reconnecter » le recrée.
        unset($ros->systemUsers['wifizone']);
        $this->postJson("/api/v1/routers/$routerId/test")->assertStatus(422)->assertJsonPath('errors.0.code', 'router_auth_failed');
        $this->postJson("/api/v1/routers/$routerId/reconnect", ['username' => 'admin', 'password' => 'faux'])->assertStatus(422);
        $this->postJson("/api/v1/routers/$routerId/reconnect", ['username' => 'admin', 'password' => self::ADMIN_PASSWORD])->assertOk()->assertJsonPath('data.rest_error', null);
        $this->postJson("/api/v1/routers/$routerId/test")->assertOk();
    }

    public function test_direct_routers_are_tenant_isolated_and_have_no_agent_token(): void
    {
        [, , , $routerId] = $this->connected();
        [, $other] = $this->tenantWithOwner(plan: 'PRO');
        Sanctum::actingAs($other);

        $this->postJson("/api/v1/routers/$routerId/test")->assertStatus(404);
        $this->postJson("/api/v1/routers/$routerId/reconnect", ['username' => 'a', 'password' => 'b'])->assertStatus(404);

        [, $owner] = $this->tenantWithOwner(plan: 'PRO');
        [, $own, , $mine] = $this->connected();
        Sanctum::actingAs($own);
        $this->postJson("/api/v1/routers/$mine/rotate-agent-token")->assertStatus(409)->assertJsonPath('errors.0.code', 'not_agent_router');
    }
}
