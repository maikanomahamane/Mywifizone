<?php

namespace Tests\Feature\Network;

use App\Models\Router;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesNetwork;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class RouterApiTest extends TestCase
{
    use CreatesNetwork, CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    public function test_owner_creates_router_and_receives_one_time_enrollment_code(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner();
        $site = $this->makeSite($tenant);
        Sanctum::actingAs($owner);

        $res = $this->postJson('/api/v1/routers', ['site_id' => $site->getKey(), 'name' => 'hAP ax2'])->assertCreated();

        $this->assertMatchesRegularExpression('/^WZ(-[A-HJKMNP-Z2-9]{5}){4}$/', $res->json('data.enrollment.code'));
        $this->assertSame('PENDING', $res->json('data.status'));
        $this->assertTrue($res->json('data.enrollment_pending'));

        $id = $res->json('data.id');
        // Le code n'est jamais restitué ensuite et seul son hachage est stocké.
        $show = $this->getJson("/api/v1/routers/$id")->assertOk();
        $this->assertArrayNotHasKey('enrollment', $show->json('data'));
        $this->assertStringNotContainsString($res->json('data.enrollment.code'), $show->getContent());
        $this->assertStringNotContainsString('hash', $show->getContent());
        $this->assertDatabaseMissing('routers', ['enrollment_hash' => $res->json('data.enrollment.code')]);
    }

    public function test_plan_limit_on_routers_is_enforced(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner(plan: 'STARTER'); // max_routers = 1
        $site = $this->makeSite($tenant);
        Sanctum::actingAs($owner);

        $this->postJson('/api/v1/routers', ['site_id' => $site->getKey(), 'name' => 'R1'])->assertCreated();
        $this->postJson('/api/v1/routers', ['site_id' => $site->getKey(), 'name' => 'R2'])
            ->assertStatus(403)->assertJsonPath('errors.0.code', 'plan_limit_reached');
    }

    public function test_site_of_another_tenant_is_rejected_like_a_missing_one(): void
    {
        [, $owner] = $this->tenantWithOwner();
        [$other] = $this->tenantWithOwner();
        $foreignSite = $this->makeSite($other);
        Sanctum::actingAs($owner);

        $this->postJson('/api/v1/routers', ['site_id' => $foreignSite->getKey(), 'name' => 'X'])->assertStatus(422);
        $this->postJson('/api/v1/routers', ['site_id' => (string) \Illuminate\Support\Str::uuid(), 'name' => 'X'])->assertStatus(422);
        $this->postJson('/api/v1/routers', ['site_id' => 'pas-un-uuid', 'name' => 'X'])->assertStatus(422);
    }

    public function test_cross_tenant_access_is_404(): void
    {
        [$a] = $this->tenantWithOwner();
        [, $ownerB] = $this->tenantWithOwner();
        [$routerA] = $this->makeRouter($a);
        Sanctum::actingAs($ownerB);

        $this->getJson("/api/v1/routers/{$routerA->id}")->assertStatus(404);
        $this->putJson("/api/v1/routers/{$routerA->id}", ['name' => 'Piraté'])->assertStatus(404);
        $this->deleteJson("/api/v1/routers/{$routerA->id}")->assertStatus(404);
        $this->postJson("/api/v1/routers/{$routerA->id}/rotate-agent-token")->assertStatus(404);
        $this->postJson("/api/v1/routers/{$routerA->id}/sync")->assertStatus(404);
        $this->getJson("/api/v1/routers/{$routerA->id}/commands")->assertStatus(404);
        $this->getJson('/api/v1/routers')->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_agent_role_can_read_but_not_manage_routers(): void
    {
        [$tenant] = $this->tenantWithOwner();
        $site = $this->makeSite($tenant);
        $this->makeRouter($tenant, $site);
        Sanctum::actingAs($this->agentFor($tenant));

        $this->getJson('/api/v1/routers')->assertOk()->assertJsonPath('meta.total', 1);
        $this->postJson('/api/v1/routers', ['site_id' => $site->getKey(), 'name' => 'R'])->assertStatus(403);
    }

    public function test_writes_are_blocked_when_subscription_is_inactive_but_reads_work(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner();
        $site = $this->makeSite($tenant);
        [$router] = $this->makeRouter($tenant, $site);
        $this->asTenant($tenant, fn () => Subscription::query()->update(['status' => 'EXPIRED']));
        Sanctum::actingAs($owner);

        $this->getJson("/api/v1/routers/{$router->id}")->assertOk();
        $this->postJson('/api/v1/routers', ['site_id' => $site->getKey(), 'name' => 'R'])->assertStatus(402);
    }

    public function test_rotating_the_token_cuts_the_old_agent_off(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner();
        [$router, $token] = $this->enrolledRouter($tenant);
        Sanctum::actingAs($owner);

        $this->withToken($token)->postJson('/api/v1/agent/poll')->assertOk();

        $res = $this->postJson("/api/v1/routers/{$router->id}/rotate-agent-token")->assertOk();
        $this->assertNotEmpty($res->json('data.enrollment.code'));

        app('auth')->forgetGuards();
        $this->withToken($token)->postJson('/api/v1/agent/poll')->assertStatus(401);
        $this->assertFalse(Router::withoutGlobalScopes()->find($router->id)->isEnrolled());
    }
}
