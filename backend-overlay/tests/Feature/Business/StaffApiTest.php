<?php

namespace Tests\Feature\Business;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class StaffApiTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    public function test_owner_creates_an_agent_who_can_log_in_and_has_agent_permissions(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner(plan: 'PRO');
        Sanctum::actingAs($owner);

        $id = $this->postJson('/api/v1/agents', ['name' => 'Aïcha', 'phone' => '+227 90 00 00 01', 'password' => 'motdepasse-1'])
            ->assertCreated()->assertJsonPath('data.status', 'ACTIVE')->assertJsonPath('data.phone', '+22790000001')->json('data.id');

        $agent = User::findOrFail($id);
        $this->assertSame($tenant->id, $agent->tenant_id);
        $this->assertSame(Role::AGENT, $agent->role->code);
        $this->assertTrue($agent->hasPermission('sales.create'));
        $this->assertFalse($agent->hasPermission('sales.view_all'));
        $this->assertFalse($agent->hasPermission('sales.void'));

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/login', ['identifier' => '+22790000001', 'password' => 'motdepasse-1'])->assertOk();
    }

    public function test_plan_limit_on_agents_is_enforced(): void
    {
        [, $owner] = $this->tenantWithOwner(plan: 'STARTER'); // max_agents = 1
        Sanctum::actingAs($owner);

        $this->postJson('/api/v1/agents', ['name' => 'A1', 'email' => 'a1@example.com', 'password' => 'motdepasse-1'])->assertCreated();
        $this->postJson('/api/v1/agents', ['name' => 'A2', 'email' => 'a2@example.com', 'password' => 'motdepasse-1'])
            ->assertStatus(403)->assertJsonPath('errors.0.code', 'plan_limit_reached');
    }

    public function test_agent_cannot_manage_agents(): void
    {
        [$tenant] = $this->tenantWithOwner();
        $agent = $this->agentFor($tenant);
        Sanctum::actingAs($agent);

        $this->getJson('/api/v1/agents')->assertStatus(403);
        $this->postJson('/api/v1/agents', ['name' => 'X', 'email' => 'x@example.com', 'password' => 'motdepasse-1'])->assertStatus(403);
        $this->putJson("/api/v1/agents/{$agent->id}", ['name' => 'Moi'])->assertStatus(403);
    }

    public function test_agents_are_isolated_between_tenants_and_owner_is_not_manageable(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner();
        $mine = $this->agentFor($tenant);
        [$other, $otherOwner] = $this->tenantWithOwner();
        $theirs = $this->agentFor($other);

        Sanctum::actingAs($owner);
        $this->getJson('/api/v1/agents')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $mine->id);
        $this->getJson("/api/v1/agents/{$theirs->id}")->assertStatus(404);
        $this->putJson("/api/v1/agents/{$theirs->id}", ['status' => 'DISABLED'])->assertStatus(404);
        $this->putJson("/api/v1/agents/{$otherOwner->id}", ['status' => 'DISABLED'])->assertStatus(404);
        $this->putJson("/api/v1/agents/{$owner->id}", ['status' => 'DISABLED'])->assertStatus(404); // jamais le propriétaire
        $this->assertSame('ACTIVE', $theirs->fresh()->status);
        $this->assertSame('ACTIVE', $owner->fresh()->status);
    }

    public function test_disabling_an_agent_cuts_access_and_revokes_tokens(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner();
        $agent = $this->agentFor($tenant);
        $token = $agent->createToken('t')->plainTextToken;

        Sanctum::actingAs($owner);
        $this->putJson("/api/v1/agents/{$agent->id}", ['status' => 'DISABLED'])->assertOk()->assertJsonPath('data.status', 'DISABLED');
        $this->assertSame(0, $agent->tokens()->count());

        Sanctum::actingAs($agent->fresh());
        $this->getJson('/api/v1/sales')->assertStatus(403);
        unset($token);
    }

    public function test_password_change_revokes_tokens_and_validation_is_strict(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner();
        $agent = $this->agentFor($tenant);
        $agent->createToken('t');
        Sanctum::actingAs($owner);

        $this->putJson("/api/v1/agents/{$agent->id}", ['password' => 'court'])->assertStatus(422);
        $this->putJson("/api/v1/agents/{$agent->id}", ['status' => 'ROOT'])->assertStatus(422);
        $this->putJson("/api/v1/agents/{$agent->id}", ['password' => 'nouveau-motdepasse'])->assertOk();
        $this->assertSame(0, $agent->tokens()->count());
        $this->postJson('/api/v1/agents', ['name' => 'Sans contact', 'password' => 'motdepasse-1'])->assertStatus(422);
    }
}
