<?php

namespace Tests\Feature\Network;

use App\Models\Voucher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesNetwork;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class VoucherFlowTest extends TestCase
{
    use CreatesNetwork, CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    /** Génère un lot de $n vouchers et simule l'agent qui confirme leur création. */
    private function generatedBatch($tenant, $owner, int $n = 3, array $profileAttrs = []): array
    {
        [$router, $token] = $this->enrolledRouter($tenant);
        $profile = $this->makeProfile($tenant, $profileAttrs);
        Sanctum::actingAs($owner);

        $batchId = $this->postJson('/api/v1/voucher-batches', [
            'router_id' => $router->id, 'profile_id' => $profile->id, 'quantity' => $n, 'label' => 'Lot test',
        ])->assertStatus(202)->json('data.id');

        return [$router, $token, $profile, $batchId];
    }

    private function agentConfirmsCreation(string $token): array
    {
        $commands = $this->withToken($token)->postJson('/api/v1/agent/poll')->assertOk()->json('data');
        $codes = [];
        foreach ($commands as $c) {
            if ($c['type'] === 'CREATE_VOUCHER') {
                $created = array_column($c['payload']['vouchers'], 'code');
                $codes = array_merge($codes, $created);
                $this->withToken($token)->postJson("/api/v1/agent/commands/{$c['id']}/result", ['success' => true, 'result' => ['created' => $created]])->assertOk();
            } else {
                $this->withToken($token)->postJson("/api/v1/agent/commands/{$c['id']}/result", ['success' => true, 'result' => []])->assertOk();
            }
        }

        return [$commands, $codes];
    }

    public function test_batch_generation_queues_profile_then_vouchers_and_agent_confirmation_makes_them_available(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner();
        [, $token, , $batchId] = $this->generatedBatch($tenant, $owner, 3);

        $this->assertSame(3, Voucher::withoutGlobalScopes()->where('batch_id', $batchId)->where('status', 'GENERATED')->where('sync_status', 'PENDING')->count());

        [$commands, $codes] = $this->agentConfirmsCreation($token);

        $this->assertSame(['SYNC_PROFILES', 'CREATE_VOUCHER'], array_column($commands, 'type'));
        $this->assertCount(3, $codes);
        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression('/^[A-HJKMNP-Z2-9]{8}$/', $code);
        }
        $this->assertSame(3, Voucher::withoutGlobalScopes()->where('batch_id', $batchId)->where('status', 'AVAILABLE')->where('sync_status', 'SYNCED')->count());

        Sanctum::actingAs($owner);
        $this->getJson("/api/v1/voucher-batches/$batchId")->assertOk()->assertJsonPath('data.counts.AVAILABLE', 3);
        $this->getJson('/api/v1/vouchers?status=AVAILABLE')->assertOk()->assertJsonPath('meta.total', 3);
    }

    public function test_vip_profile_produces_vip_vouchers(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner();
        [, $token] = $this->generatedBatch($tenant, $owner, 2, ['name' => 'VIP', 'kind' => 'VIP', 'price' => 0, 'duration_minutes' => null, 'rate_limit' => null]);

        $this->agentConfirmsCreation($token);

        $this->assertSame(2, Voucher::withoutGlobalScopes()->where('status', 'VIP')->where('is_vip', true)->count());
    }

    public function test_definitive_failure_marks_vouchers_sync_failed(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner();
        [, $token, , $batchId] = $this->generatedBatch($tenant, $owner, 2);

        $commands = $this->withToken($token)->postJson('/api/v1/agent/poll')->json('data');
        $create = collect($commands)->firstWhere('type', 'CREATE_VOUCHER');
        $this->withToken($token)->postJson("/api/v1/agent/commands/{$create['id']}/result", ['success' => false, 'error' => 'refused', 'retryable' => false])
            ->assertOk()->assertJsonPath('data.status', 'FAILED');

        $this->assertSame(2, Voucher::withoutGlobalScopes()->where('batch_id', $batchId)->where('status', 'GENERATED')->where('sync_status', 'FAILED')->count());
        $this->assertDatabaseHas('network_events', ['type' => 'VOUCHER_SYNC_FAILED']);
    }

    public function test_batch_quantity_is_capped_by_plan_and_router_must_be_enrolled(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner(plan: 'STARTER'); // max_batch_size = 100
        [$router] = $this->enrolledRouter($tenant);
        [$pending] = $this->makeRouter($tenant);
        $profile = $this->makeProfile($tenant);
        Sanctum::actingAs($owner);

        $this->postJson('/api/v1/voucher-batches', ['router_id' => $router->id, 'profile_id' => $profile->id, 'quantity' => 101])->assertStatus(422);
        $this->postJson('/api/v1/voucher-batches', ['router_id' => $router->id, 'profile_id' => $profile->id, 'quantity' => 0])->assertStatus(422);
        $this->postJson('/api/v1/voucher-batches', ['router_id' => $pending->id, 'profile_id' => $profile->id, 'quantity' => 1])
            ->assertStatus(409)->assertJsonPath('errors.0.code', 'router_not_enrolled');
    }

    public function test_batch_of_120_is_split_in_chunks_of_50(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner(plan: 'PRO');
        [, $token] = $this->generatedBatch($tenant, $owner, 120);

        $commands = $this->withToken($token)->postJson('/api/v1/agent/poll')->json('data');
        $creates = array_values(array_filter($commands, fn ($c) => $c['type'] === 'CREATE_VOUCHER'));

        $this->assertSame(Voucher::withoutGlobalScopes()->count(), 120);
        $this->assertNotEmpty($creates);
        foreach ($creates as $c) {
            $this->assertLessThanOrEqual(50, count($c['payload']['vouchers']));
        }
    }

    public function test_foreign_router_or_profile_cannot_be_used_in_a_batch(): void
    {
        [$a, $ownerA] = $this->tenantWithOwner();
        [$b] = $this->tenantWithOwner();
        [$routerB] = $this->enrolledRouter($b);
        $profileB = $this->makeProfile($b);
        [$routerA] = $this->enrolledRouter($a);
        $profileA = $this->makeProfile($a);
        Sanctum::actingAs($ownerA);

        $this->postJson('/api/v1/voucher-batches', ['router_id' => $routerB->id, 'profile_id' => $profileA->id, 'quantity' => 1])->assertStatus(422);
        $this->postJson('/api/v1/voucher-batches', ['router_id' => $routerA->id, 'profile_id' => $profileB->id, 'quantity' => 1])->assertStatus(422);
        $this->assertSame(0, Voucher::withoutGlobalScopes()->count());
    }

    public function test_revoke_is_idempotent_queues_one_command_and_is_refused_for_used_vouchers(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner();
        [, $token] = $this->generatedBatch($tenant, $owner, 2);
        [, $codes] = $this->agentConfirmsCreation($token);

        Sanctum::actingAs($owner);
        $v = Voucher::withoutGlobalScopes()->where('code', $codes[0])->first();
        $used = Voucher::withoutGlobalScopes()->where('code', $codes[1])->first();

        $this->postJson("/api/v1/vouchers/{$v->id}/revoke")->assertOk()->assertJsonPath('data.status', 'REVOKED');
        $this->postJson("/api/v1/vouchers/{$v->id}/revoke")->assertOk()->assertJsonPath('data.status', 'REVOKED');

        $this->assertSame(1, \App\Models\RouterCommand::withoutGlobalScopes()->where('type', 'REVOKE_VOUCHER')->count());
        $revokeCmd = $this->withToken($token)->postJson('/api/v1/agent/poll')->json('data.0');
        $this->assertSame('REVOKE_VOUCHER', $revokeCmd['type']);
        $this->assertSame($codes[0], $revokeCmd['payload']['code']);

        Voucher::withoutGlobalScopes()->whereKey($used->id)->update(['status' => 'USED']);
        $this->postJson("/api/v1/vouchers/{$used->id}/revoke")->assertStatus(409)->assertJsonPath('errors.0.code', 'voucher_not_revocable');
    }

    public function test_agent_role_cannot_revoke_and_cross_tenant_revoke_is_404(): void
    {
        [$a, $ownerA] = $this->tenantWithOwner();
        [, $token] = $this->generatedBatch($a, $ownerA, 1);
        $this->agentConfirmsCreation($token);
        $v = Voucher::withoutGlobalScopes()->first();
        [, $ownerB] = $this->tenantWithOwner();

        Sanctum::actingAs($this->agentFor($a));
        $this->postJson("/api/v1/vouchers/{$v->id}/revoke")->assertStatus(403);

        Sanctum::actingAs($ownerB);
        $this->postJson("/api/v1/vouchers/{$v->id}/revoke")->assertStatus(404);
        $this->getJson("/api/v1/vouchers/{$v->id}")->assertStatus(404);
        $this->assertSame('AVAILABLE', Voucher::withoutGlobalScopes()->whereKey($v->id)->value('status'));
    }

    public function test_sessions_activate_vouchers_then_finished_codes_mark_them_used(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner();
        [$router, $token] = $this->generatedBatch($tenant, $owner, 1);
        [, $codes] = $this->agentConfirmsCreation($token);

        $this->withToken($token)->postJson('/api/v1/agent/heartbeat', ['sessions' => [[
            'id' => '*1A', 'username' => $codes[0], 'address' => '10.5.50.10', 'mac' => 'aa:bb:cc:dd:ee:ff',
            'uptime_seconds' => 60, 'bytes_in' => 1000, 'bytes_out' => 2000,
        ]]])->assertOk();

        $this->assertSame('ACTIVE', Voucher::withoutGlobalScopes()->where('code', $codes[0])->value('status'));

        Sanctum::actingAs($owner);
        $list = $this->getJson('/api/v1/sessions')->assertOk()->assertJsonPath('meta.total', 1);
        $this->assertSame('AA:BB:CC:DD:EE:FF', $list->json('data.0.mac'));
        $this->getJson("/api/v1/routers/{$router->id}")->assertJsonPath('data.clients_count', 1);

        // Fin de session + code épuisé côté routeur.
        $this->withToken($token)->postJson('/api/v1/agent/heartbeat', ['sessions' => [], 'finished_codes' => [$codes[0]]])->assertOk();

        $this->assertSame('USED', Voucher::withoutGlobalScopes()->where('code', $codes[0])->value('status'));
        $this->getJson('/api/v1/sessions')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/sessions?active=0')->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_disconnect_queues_a_command_for_the_agent(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner();
        [, $token] = $this->generatedBatch($tenant, $owner, 1);
        [, $codes] = $this->agentConfirmsCreation($token);
        $this->withToken($token)->postJson('/api/v1/agent/heartbeat', ['sessions' => [['id' => '*2B', 'username' => $codes[0]]]])->assertOk();

        Sanctum::actingAs($owner);
        $sessionId = $this->getJson('/api/v1/sessions')->json('data.0.id');
        $this->postJson("/api/v1/sessions/$sessionId/disconnect")->assertStatus(202)->assertJsonPath('data.type', 'DISCONNECT_USER');

        $cmd = $this->withToken($token)->postJson('/api/v1/agent/poll')->json('data.0');
        $this->assertSame('DISCONNECT_USER', $cmd['type']);
        $this->assertSame('*2B', $cmd['payload']['session_id']);
    }

    public function test_commands_never_carry_unvalidated_free_text(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner();
        [$router, $token] = $this->enrolledRouter($tenant);
        Sanctum::actingAs($owner);

        // Débit invalide : refusé à la création du profil (liste blanche), donc jamais envoyé au routeur.
        foreach (["2M/2M; /system reset-configuration", "2M/2M\n/system reset-configuration", '$(reboot)', '2M/2M '.str_repeat('1', 30)] as $bad) {
            $this->postJson('/api/v1/profiles', ['name' => 'Piège', 'price' => 100, 'currency' => 'XOF', 'rate_limit' => $bad])->assertStatus(422);
        }
        $this->assertDatabaseCount('hotspot_profiles', 0);

        // Résultat de création contenant un code piégé : refusé par l'API.
        $profile = $this->makeProfile($tenant);
        $this->postJson('/api/v1/voucher-batches', ['router_id' => $router->id, 'profile_id' => $profile->id, 'quantity' => 1])->assertStatus(202);
        $create = collect($this->withToken($token)->postJson('/api/v1/agent/poll')->json('data'))->firstWhere('type', 'CREATE_VOUCHER');
        $this->withToken($token)->postJson("/api/v1/agent/commands/{$create['id']}/result", ['success' => true, 'result' => ['created' => ['ABC; reboot']]])->assertStatus(422);

        // Les noms de profil côté routeur sont dérivés de l'UUID, jamais du nom saisi.
        $this->assertMatchesRegularExpression('/^wz-[0-9a-f]{8}$/', $create['payload']['vouchers'][0]['profile']);
    }

    public function test_profile_crud_rules(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner();
        Sanctum::actingAs($owner);

        $id = $this->postJson('/api/v1/profiles', ['name' => '1 jour', 'price' => 500, 'currency' => 'xof', 'duration_minutes' => 1440, 'rate_limit' => '2M/2M'])
            ->assertCreated()->assertJsonPath('data.currency', 'XOF')->json('data.id');

        // Les montants sont des entiers : un décimal est refusé.
        $this->postJson('/api/v1/profiles', ['name' => 'X', 'price' => 12.5, 'currency' => 'XOF'])->assertStatus(422);

        $this->putJson("/api/v1/profiles/$id", ['price' => 600])->assertOk()->assertJsonPath('data.price', 600);

        // Profil utilisé : suppression refusée.
        [$router, $token] = $this->enrolledRouter($tenant);
        $this->postJson('/api/v1/voucher-batches', ['router_id' => $router->id, 'profile_id' => $id, 'quantity' => 1])->assertStatus(202);
        $this->deleteJson("/api/v1/profiles/$id")->assertStatus(409)->assertJsonPath('errors.0.code', 'profile_in_use');
        $this->assertNotEmpty($token);

        // Les vouchers déjà générés gardent le prix d'origine.
        $this->assertSame(600, (int) Voucher::withoutGlobalScopes()->first()->price);
        $this->putJson("/api/v1/profiles/$id", ['price' => 900])->assertOk();
        $this->assertSame(600, (int) Voucher::withoutGlobalScopes()->first()->price);
    }

    public function test_voucher_expiry_task_expires_and_queues_disable(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner();
        [, $token] = $this->generatedBatch($tenant, $owner, 1);
        [, $codes] = $this->agentConfirmsCreation($token);

        Voucher::withoutGlobalScopes()->update(['valid_until' => now()->subMinute()]);
        $this->artisan('vouchers:expire')->assertSuccessful();

        $this->assertSame('EXPIRED', Voucher::withoutGlobalScopes()->where('code', $codes[0])->value('status'));
        $cmd = $this->withToken($token)->postJson('/api/v1/agent/poll')->json('data.0');
        $this->assertSame('DISABLE_VOUCHER', $cmd['type']);
    }
}
