<?php

namespace Tests\Feature\Ops;

use App\Domain\Payments\PaymentStatus;
use App\Models\PaymentTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class OpsApiTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    public function test_api_responses_carry_security_headers_and_a_request_id(): void
    {
        $res = $this->getJson('/api/v1/health')->assertOk();

        $this->assertSame('nosniff', $res->headers->get('X-Content-Type-Options'));
        $this->assertSame('DENY', $res->headers->get('X-Frame-Options'));
        $this->assertSame('no-referrer', $res->headers->get('Referrer-Policy'));
        $this->assertStringContainsString('no-store', $res->headers->get('Cache-Control'));
        $this->assertNotEmpty($res->headers->get('X-Request-Id'));
        $this->assertNull($res->headers->get('Strict-Transport-Security'), 'HSTS seulement en HTTPS');

        $https = $this->getJson('https://localhost/api/v1/health')->assertOk();
        $this->assertStringContainsString('max-age=', (string) $https->headers->get('Strict-Transport-Security'));
    }

    public function test_readiness_probe_reports_components_without_details(): void
    {
        $this->getJson('/api/v1/health/ready')->assertOk()
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.checks.database', 'ok')
            ->assertJsonPath('data.checks.cache', 'ok')
            ->assertJsonMissingPath('data.version');
    }

    public function test_metrics_endpoint_is_disabled_without_token_and_checks_it_otherwise(): void
    {
        config(['monitoring.metrics_token' => '']);
        $this->getJson('/api/v1/internal/metrics')->assertStatus(404);

        config(['monitoring.metrics_token' => 'ops-secret-token']);
        $this->getJson('/api/v1/internal/metrics')->assertStatus(401);
        $this->getJson('/api/v1/internal/metrics', ['Authorization' => 'Bearer wrong'])->assertStatus(401);
    }

    public function test_metrics_expose_aggregates_only(): void
    {
        config(['monitoring.metrics_token' => 'ops-secret-token']);
        [$tenant] = $this->tenantWithOwner(['name' => 'Secret Boutique Name'], plan: 'PRO');

        $res = $this->get('/api/v1/internal/metrics', ['Authorization' => 'Bearer ops-secret-token'])->assertOk();
        $body = $res->getContent();

        $this->assertStringContainsString('text/plain', $res->headers->get('Content-Type'));
        $this->assertStringContainsString('# TYPE wifizone_routers gauge', $body);
        $this->assertStringContainsString('wifizone_tenants{status="active"} 1', $body);
        $this->assertStringContainsString('wifizone_payments_stuck 0', $body);
        $this->assertStringNotContainsString('Secret Boutique Name', $body);
        $this->assertStringNotContainsString($tenant->getKey(), $body);
    }

    public function test_monitor_check_flags_a_stuck_payment_and_logs_it(): void
    {
        [$tenant] = $this->tenantWithOwner(plan: 'PRO');
        $this->asTenant($tenant, function () {
            $tx = PaymentTransaction::create([
                'invoice_id' => null, 'provider' => 'mock', 'idempotency_key' => 'k-'.Str::random(6), 'amount' => 5000, 'currency' => 'XOF', 'status' => PaymentStatus::PENDING,
            ]);
            DB::table('payment_transactions')->where('id', $tx->getKey())->update(['created_at' => now()->subHours(5)]);
        });

        Log::spy();
        $this->assertSame(1, Artisan::call('monitor:check'), 'alerte critique -> code de sortie non nul');
        Log::shouldHaveReceived('error')->withArgs(fn ($message) => $message === 'ALERT payments_stuck')->once();
    }

    public function test_monitor_check_is_quiet_when_everything_is_fine(): void
    {
        $this->assertSame(0, Artisan::call('monitor:check'));
        $this->assertStringContainsString('Aucune alerte', Artisan::output());
    }

    public function test_request_log_uses_the_route_template_never_the_secret_in_the_url(): void
    {
        Log::spy();
        $token = str_repeat('a', 32);
        $this->getJson("/api/v1/portal/orders/$token?x=secret");

        Log::shouldHaveReceived('log')->withArgs(function ($level, $message, $context = []) use ($token) {
            return $message === 'http_request'
                && str_contains($context['route'], 'orders/{token}')
                && ! str_contains(json_encode($context), $token)
                && ! str_contains(json_encode($context), 'secret');
        })->once();
    }
}
