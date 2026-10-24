<?php

namespace Tests\Feature\Network;

use App\Domain\Network\CommandType;
use App\Models\Router;
use App\Models\RouterCommand;
use App\Models\Tenant;
use App\Services\CommandQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesNetwork;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class AgentFlowTest extends TestCase
{
    use CreatesNetwork, CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    public function test_enrollment_code_is_single_use_and_returns_a_token(): void
    {
        [$tenant] = $this->tenantWithOwner();
        [$router, $code] = $this->makeRouter($tenant);

        $res = $this->postJson('/api/v1/agent/enroll', ['enrollment_code' => $code, 'agent_version' => '1.0', 'routeros_version' => '7.15'])
            ->assertCreated();

        $this->assertStringStartsWith('wza_', $res->json('data.agent_token'));
        $this->assertSame($router->id, $res->json('data.router_id'));
        $this->assertDatabaseMissing('routers', ['agent_token_hash' => $res->json('data.agent_token')]);

        // Le même code ne fonctionne plus.
        $this->postJson('/api/v1/agent/enroll', ['enrollment_code' => $code])
            ->assertStatus(422)->assertJsonPath('errors.0.code', 'invalid_enrollment_code');
    }

    public function test_enrollment_tolerates_lowercase_and_spaces_but_rejects_garbage_and_expired_codes(): void
    {
        [$tenant] = $this->tenantWithOwner();
        [$router, $code] = $this->makeRouter($tenant);

        $this->postJson('/api/v1/agent/enroll', ['enrollment_code' => 'WZ-AAAAA-AAAAA-AAAAA-AAAAA'])->assertStatus(422);

        Router::withoutGlobalScopes()->whereKey($router->id)->update(['enrollment_expires_at' => now()->subMinute()]);
        $this->postJson('/api/v1/agent/enroll', ['enrollment_code' => $code])->assertStatus(422);

        Router::withoutGlobalScopes()->whereKey($router->id)->update(['enrollment_expires_at' => now()->addHour()]);
        $this->postJson('/api/v1/agent/enroll', ['enrollment_code' => '  '.strtolower($code).' '])->assertCreated();
    }

    public function test_agent_endpoints_require_a_valid_token(): void
    {
        $this->postJson('/api/v1/agent/poll')->assertStatus(401);
        $this->withToken('wza_'.str_repeat('x', 48))->postJson('/api/v1/agent/poll')->assertStatus(401);
        $this->withToken('abc')->postJson('/api/v1/agent/heartbeat')->assertStatus(401);
    }

    public function test_suspended_tenant_agent_is_refused(): void
    {
        [$tenant] = $this->tenantWithOwner();
        [, $token] = $this->enrolledRouter($tenant);

        Tenant::whereKey($tenant->getKey())->update(['status' => 'SUSPENDED']);

        $this->withToken($token)->postJson('/api/v1/agent/poll')->assertStatus(403);
    }

    public function test_heartbeat_marks_router_online_and_stores_whitelisted_health_only(): void
    {
        [$tenant] = $this->tenantWithOwner();
        [$router, $token] = $this->enrolledRouter($tenant);

        $this->withToken($token)->postJson('/api/v1/agent/heartbeat', [
            'health' => ['board_name' => 'hAP ax2', 'version' => '7.15', 'cpu_load' => 12, 'evil' => 'x'],
        ])->assertOk();

        $fresh = Router::withoutGlobalScopes()->find($router->id);
        $this->assertSame('ONLINE', $fresh->status);
        $this->assertNotNull($fresh->last_seen_at);
        $this->assertSame(12, $fresh->health['cpu_load']);
        $this->assertArrayNotHasKey('evil', $fresh->health);
    }

    public function test_heartbeat_rejects_malformed_sessions(): void
    {
        [$tenant] = $this->tenantWithOwner();
        [, $token] = $this->enrolledRouter($tenant);

        $this->withToken($token)->postJson('/api/v1/agent/heartbeat', ['sessions' => [['username' => 'X']]])->assertStatus(422);
        $this->withToken($token)->postJson('/api/v1/agent/heartbeat', ['sessions' => [['id' => '*1', 'username' => 'X', 'address' => 'pas-une-ip']]])->assertStatus(422);
    }

    public function test_poll_leases_commands_in_creation_order_and_does_not_deliver_twice(): void
    {
        [$tenant] = $this->tenantWithOwner();
        [$router, $token] = $this->enrolledRouter($tenant);

        $this->asTenant($tenant, function () use ($router) {
            $q = app(CommandQueue::class);
            $q->enqueue($router, CommandType::FETCH_HEALTH, []);
            $q->enqueue($router, CommandType::FETCH_SESSIONS, []);
        });

        $first = $this->withToken($token)->postJson('/api/v1/agent/poll')->assertOk()->json('data');
        $this->assertSame([CommandType::FETCH_HEALTH, CommandType::FETCH_SESSIONS], array_column($first, 'type'));
        $this->assertSame(1, $first[0]['attempt']);

        // Bail en cours : la deuxième interrogation ne les redonne pas.
        $this->withToken($token)->postJson('/api/v1/agent/poll')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_expired_lease_puts_the_command_back_in_the_queue(): void
    {
        [$tenant] = $this->tenantWithOwner();
        [$router, $token] = $this->enrolledRouter($tenant);
        $this->asTenant($tenant, fn () => app(CommandQueue::class)->enqueue($router, CommandType::FETCH_HEALTH, []));

        $this->withToken($token)->postJson('/api/v1/agent/poll')->assertJsonCount(1, 'data');

        RouterCommand::withoutGlobalScopes()->update(['leased_until' => now()->subSeconds(5)]);

        $again = $this->withToken($token)->postJson('/api/v1/agent/poll')->assertOk()->json('data');
        $this->assertCount(1, $again);
        $this->assertSame(2, $again[0]['attempt']);
    }

    public function test_retryable_failure_waits_for_backoff_and_then_fails_for_good(): void
    {
        [$tenant] = $this->tenantWithOwner();
        [$router, $token] = $this->enrolledRouter($tenant);
        $cmd = $this->asTenant($tenant, fn () => app(CommandQueue::class)->enqueue($router, CommandType::FETCH_HEALTH, []));

        $this->withToken($token)->postJson('/api/v1/agent/poll');
        $this->withToken($token)->postJson("/api/v1/agent/commands/{$cmd->id}/result", ['success' => false, 'error' => 'timeout'])
            ->assertOk()->assertJsonPath('data.status', 'PENDING');

        // Délai de reprise : rien à livrer tout de suite.
        $this->withToken($token)->postJson('/api/v1/agent/poll')->assertJsonCount(0, 'data');

        // Dernière tentative : échec définitif.
        RouterCommand::withoutGlobalScopes()->whereKey($cmd->id)->update(['attempts' => 3, 'status' => 'LEASED', 'leased_until' => now()->addMinute()]);
        $this->withToken($token)->postJson("/api/v1/agent/commands/{$cmd->id}/result", ['success' => false, 'error' => 'timeout'])
            ->assertOk()->assertJsonPath('data.status', 'FAILED');
        $this->assertDatabaseHas('network_events', ['router_id' => $router->id, 'type' => 'COMMAND_FAILED']);
    }

    public function test_result_replay_is_accepted_without_effect(): void
    {
        [$tenant] = $this->tenantWithOwner();
        [$router, $token] = $this->enrolledRouter($tenant);
        $cmd = $this->asTenant($tenant, fn () => app(CommandQueue::class)->enqueue($router, CommandType::FETCH_HEALTH, []));
        $this->withToken($token)->postJson('/api/v1/agent/poll');

        $body = ['success' => true, 'result' => ['health' => ['cpu_load' => 5]]];
        $this->withToken($token)->postJson("/api/v1/agent/commands/{$cmd->id}/result", $body)
            ->assertOk()->assertJsonPath('data.duplicate', false);
        $this->withToken($token)->postJson("/api/v1/agent/commands/{$cmd->id}/result", $body)
            ->assertOk()->assertJsonPath('data.duplicate', true)->assertJsonPath('data.status', 'SUCCEEDED');
    }

    public function test_agent_cannot_answer_for_another_router_or_tenant(): void
    {
        [$a] = $this->tenantWithOwner();
        [$b] = $this->tenantWithOwner();
        [, $tokenA] = $this->enrolledRouter($a);
        [$routerB] = $this->enrolledRouter($b);
        $cmdB = $this->asTenant($b, fn () => app(CommandQueue::class)->enqueue($routerB, CommandType::FETCH_HEALTH, []));

        $this->withToken($tokenA)->postJson("/api/v1/agent/commands/{$cmdB->id}/result", ['success' => true, 'result' => ['health' => []]])
            ->assertStatus(404);
        $this->assertDatabaseHas('router_commands', ['id' => $cmdB->id, 'status' => 'PENDING']);

        // Et un second routeur du MÊME tenant n'est pas accessible non plus.
        [$router1] = $this->enrolledRouter($a);
        [$router2, $token2] = $this->enrolledRouter($a);
        $cmd1 = $this->asTenant($a, fn () => app(CommandQueue::class)->enqueue($router1, CommandType::FETCH_HEALTH, []));
        $this->withToken($token2)->postJson("/api/v1/agent/commands/{$cmd1->id}/result", ['success' => true, 'result' => ['health' => []]])
            ->assertStatus(404);
        $this->assertNotSame($router1->id, $router2->id);
    }

    public function test_maintenance_marks_silent_routers_offline_once(): void
    {
        [$tenant] = $this->tenantWithOwner();
        [$router, $token] = $this->enrolledRouter($tenant);
        $this->withToken($token)->postJson('/api/v1/agent/heartbeat')->assertOk();

        Router::withoutGlobalScopes()->whereKey($router->id)->update(['last_seen_at' => now()->subMinutes(10)]);

        $this->artisan('network:maintain')->assertSuccessful();
        $this->artisan('network:maintain')->assertSuccessful();

        $this->assertSame('OFFLINE', Router::withoutGlobalScopes()->find($router->id)->status);
        $this->assertSame(1, \App\Models\NetworkEvent::withoutGlobalScopes()->where('router_id', $router->id)->where('type', 'ROUTER_OFFLINE')->count());
    }
}
