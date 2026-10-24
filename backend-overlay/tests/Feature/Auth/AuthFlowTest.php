<?php

namespace Tests\Feature\Auth;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class AuthFlowTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    private function registerPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Ousmane',
            'business_name' => 'WiFi Zone Maradi',
            'phone' => '+227 90 12 34 56',
            'password' => 'motdepasse-solide',
            'device_name' => 'tests',
        ], $overrides);
    }

    public function test_register_creates_tenant_owner_trial_and_tokens(): void
    {
        $res = $this->postJson('/api/v1/auth/register', $this->registerPayload());

        $res->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.role', Role::OWNER)
            ->assertJsonPath('data.user.phone', '+22790123456')
            ->assertJsonStructure(['data' => ['tokens' => ['access_token', 'refresh_token', 'access_expires_at']], 'meta', 'errors']);

        $tenant = Tenant::firstOrFail();
        $this->assertSame($tenant->getKey(), User::firstOrFail()->tenant_id);
        $this->assertDatabaseHas('subscriptions', ['tenant_id' => $tenant->getKey(), 'status' => 'TRIAL']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.register']);
    }

    public function test_register_requires_email_or_phone_and_validates_envelope(): void
    {
        $this->postJson('/api/v1/auth/register', ['name' => 'X', 'business_name' => 'Y', 'password' => 'motdepasse-solide'])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('errors.0.code', 'validation_failed');
    }

    public function test_register_cannot_choose_role_or_tenant(): void
    {
        $this->postJson('/api/v1/auth/register', $this->registerPayload([
            'role' => Role::SUPER_ADMIN, 'role_id' => 1, 'tenant_id' => 'x',
        ]))->assertCreated()->assertJsonPath('data.user.role', Role::OWNER);
    }

    public function test_login_by_phone_and_email(): void
    {
        $this->postJson('/api/v1/auth/register', $this->registerPayload(['email' => 'Owner@Example.com']))->assertCreated();

        $this->postJson('/api/v1/auth/login', ['identifier' => '+227 90 12 34 56', 'password' => 'motdepasse-solide'])->assertOk();
        $this->postJson('/api/v1/auth/login', ['identifier' => 'owner@example.com', 'password' => 'motdepasse-solide'])->assertOk();
    }

    public function test_wrong_password_is_rejected_and_audited_without_leaking_which_part_failed(): void
    {
        $this->postJson('/api/v1/auth/register', $this->registerPayload())->assertCreated();

        $this->postJson('/api/v1/auth/login', ['identifier' => '+22790123456', 'password' => 'faux'])
            ->assertStatus(401)->assertJsonPath('errors.0.code', 'invalid_credentials');
        $this->postJson('/api/v1/auth/login', ['identifier' => 'inconnu@example.com', 'password' => 'faux'])
            ->assertStatus(401)->assertJsonPath('errors.0.code', 'invalid_credentials');

        $this->assertSame(2, AuditLog::where('action', 'auth.login_failed')->count());
    }

    public function test_me_returns_permissions_and_requires_authentication(): void
    {
        $this->getJson('/api/v1/auth/me')->assertStatus(401)->assertJsonPath('errors.0.code', 'unauthenticated');

        $tokens = $this->postJson('/api/v1/auth/register', $this->registerPayload())->json('data.tokens');

        $this->app['auth']->forgetGuards();
        $res = $this->withToken($tokens['access_token'])->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.user.role', Role::OWNER);

        $this->assertContains('sites.manage', $res->json('data.user.permissions'));
    }

    public function test_refresh_rotates_tokens_and_old_access_token_stops_working(): void
    {
        $tokens = $this->postJson('/api/v1/auth/register', $this->registerPayload())->json('data.tokens');

        $new = $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $tokens['refresh_token']])
            ->assertOk()->json('data.tokens');

        $this->assertNotSame($tokens['access_token'], $new['access_token']);
        $this->assertNotSame($tokens['refresh_token'], $new['refresh_token']);

        $this->app['auth']->forgetGuards();
        $this->withToken($new['access_token'])->getJson('/api/v1/auth/me')->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withToken($tokens['access_token'])->getJson('/api/v1/auth/me')->assertStatus(401);
    }

    public function test_reusing_a_consumed_refresh_token_revokes_the_whole_family(): void
    {
        $first = $this->postJson('/api/v1/auth/register', $this->registerPayload())->json('data.tokens');
        $second = $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $first['refresh_token']])->json('data.tokens');

        // Rejeu de l'ancien refresh token (vol probable)
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $first['refresh_token']])->assertStatus(401);

        // Le jeton légitime suivant est lui aussi révoqué
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $second['refresh_token']])->assertStatus(401);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.refresh_reuse_detected']);
    }

    public function test_refresh_after_logout_is_refused_without_killing_other_sessions(): void
    {
        $this->postJson('/api/v1/auth/register', $this->registerPayload(['email' => 'o@example.com']))->assertCreated();
        $laptop = $this->postJson('/api/v1/auth/login', ['identifier' => 'o@example.com', 'password' => 'motdepasse-solide'])->json('data.tokens');
        $phone = $this->postJson('/api/v1/auth/login', ['identifier' => 'o@example.com', 'password' => 'motdepasse-solide'])->json('data.tokens');

        $this->withToken($laptop['access_token'])->postJson('/api/v1/auth/logout')->assertOk();
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $laptop['refresh_token']])->assertStatus(401);

        // L'autre appareil n'est pas touché : un simple logout n'est pas un signe de vol.
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $phone['refresh_token']])->assertOk();
    }

    public function test_invalid_refresh_token_is_rejected(): void
    {
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => 'abc|def'])->assertStatus(401);
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => 'nopipe'])->assertStatus(401);
    }

    public function test_logout_revokes_access_and_refresh_tokens(): void
    {
        $tokens = $this->postJson('/api/v1/auth/register', $this->registerPayload())->json('data.tokens');

        $this->withToken($tokens['access_token'])->postJson('/api/v1/auth/logout')->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withToken($tokens['access_token'])->getJson('/api/v1/auth/me')->assertStatus(401);
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $tokens['refresh_token']])->assertStatus(401);
    }

    public function test_suspended_tenant_cannot_login(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner();
        $tenant->update(['status' => Tenant::STATUS_SUSPENDED]);

        $this->postJson('/api/v1/auth/login', ['identifier' => $owner->email, 'password' => 'password-123'])
            ->assertStatus(403)->assertJsonPath('errors.0.code', 'tenant_suspended');
    }
}
