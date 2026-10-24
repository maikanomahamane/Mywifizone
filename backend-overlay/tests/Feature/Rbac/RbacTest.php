<?php

namespace Tests\Feature\Rbac;

use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class RbacTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/v1/sites')->assertStatus(401);
        $this->postJson('/api/v1/sites', ['name' => 'X'])->assertStatus(401);
    }

    public function test_owner_can_manage_sites(): void
    {
        [, $owner] = $this->tenantWithOwner();
        Sanctum::actingAs($owner);

        $id = $this->postJson('/api/v1/sites', ['name' => 'Zone Centre'])->assertCreated()->json('data.id');
        $this->putJson("/api/v1/sites/$id", ['name' => 'Zone Centre 2'])->assertOk();
        $this->deleteJson("/api/v1/sites/$id")->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'site.created']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'site.deleted']);
    }

    public function test_agent_can_view_but_not_manage_sites(): void
    {
        [$tenant] = $this->tenantWithOwner();
        $site = $this->asTenant($tenant, fn () => Site::factory()->create());
        $agent = $this->agentFor($tenant);

        Sanctum::actingAs($agent);
        $this->getJson('/api/v1/sites')->assertOk();
        $this->getJson("/api/v1/sites/{$site->id}")->assertOk();
        $this->postJson('/api/v1/sites', ['name' => 'Non'])->assertStatus(403)->assertJsonPath('errors.0.code', 'forbidden');
        $this->putJson("/api/v1/sites/{$site->id}", ['name' => 'Non'])->assertStatus(403);
        $this->deleteJson("/api/v1/sites/{$site->id}")->assertStatus(403);
    }

    public function test_agent_permissions_are_enforced_server_side_in_me_payload(): void
    {
        [$tenant] = $this->tenantWithOwner();
        Sanctum::actingAs($this->agentFor($tenant));

        $perms = $this->getJson('/api/v1/auth/me')->assertOk()->json('data.user.permissions');
        $this->assertContains('tickets.create', $perms);
        $this->assertNotContains('sites.manage', $perms);
        $this->assertNotContains('agents.manage', $perms);
    }

    public function test_super_admin_has_no_tenant_and_cannot_use_tenant_routes(): void
    {
        Sanctum::actingAs($this->superAdmin());

        $this->getJson('/api/v1/sites')->assertStatus(403)->assertJsonPath('errors.0.code', 'tenant_required');
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.user.tenant', null);
    }

    public function test_suspended_tenant_is_blocked_even_with_a_valid_token(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner();
        Sanctum::actingAs($owner);
        $this->getJson('/api/v1/sites')->assertOk();

        $tenant->update(['status' => 'SUSPENDED']);
        $this->getJson('/api/v1/sites')->assertStatus(403)->assertJsonPath('errors.0.code', 'tenant_suspended');
    }

    public function test_disabled_user_is_blocked(): void
    {
        [, $owner] = $this->tenantWithOwner();
        $owner->forceFill(['status' => User::STATUS_DISABLED])->save();

        Sanctum::actingAs($owner);
        $this->getJson('/api/v1/sites')->assertStatus(403)->assertJsonPath('errors.0.code', 'account_disabled');
    }

    public function test_site_name_is_validated(): void
    {
        [, $owner] = $this->tenantWithOwner();
        Sanctum::actingAs($owner);

        $this->postJson('/api/v1/sites', [])->assertStatus(422)->assertJsonPath('errors.0.field', 'name');
    }

    public function test_responses_carry_a_request_id(): void
    {
        $this->getJson('/api/v1/health')->assertOk()->assertHeader('X-Request-Id');
    }
}
