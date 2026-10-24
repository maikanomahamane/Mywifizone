<?php

namespace Tests\Feature\Documents;

use App\Infrastructure\Documents\PdfEngine;
use App\Models\AuditLog;
use App\Models\VoucherBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesNetwork;
use Tests\Concerns\CreatesTenants;
use Tests\Support\FakePdfEngine;
use Tests\TestCase;

class TicketPdfApiTest extends TestCase
{
    use CreatesNetwork, CreatesTenants, RefreshDatabase;

    private FakePdfEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->engine = new FakePdfEngine;
        $this->app->instance(PdfEngine::class, $this->engine);
    }

    /** @return array{0: \App\Models\Tenant, 1: \App\Models\User, 2: \App\Models\Router, 3: \App\Models\HotspotProfile, 4: array<int, \App\Models\Voucher>} */
    private function stock(string $plan = 'PRO', int $n = 3): array
    {
        [$tenant, $owner] = $this->tenantWithOwner(plan: $plan);
        [$router] = $this->enrolledRouter($tenant);
        $profile = $this->makeProfile($tenant, ['name' => '1 heure']);
        $vouchers = [];
        for ($i = 0; $i < $n; $i++) {
            $vouchers[] = $this->makeVoucher($tenant, $router, $profile);
        }

        return [$tenant, $owner, $router, $profile, $vouchers];
    }

    public function test_exports_a_batch_as_pdf_with_codes_and_qr_on_pro(): void
    {
        [$tenant, $owner, $router, $profile, $vouchers] = $this->stock('PRO', 2);
        $batch = $this->asTenant($tenant, fn () => VoucherBatch::create(['site_id' => $router->site_id, 'router_id' => $router->id, 'profile_id' => $profile->id, 'quantity' => 2]));
        foreach ($vouchers as $v) {
            $this->asTenant($tenant, fn () => $v->update(['batch_id' => $batch->id]));
        }
        Sanctum::actingAs($owner);

        $res = $this->postJson('/api/v1/tickets/pdf', ['format' => 'a4', 'batch_id' => $batch->id])->assertOk();

        $this->assertSame('application/pdf', $res->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment; filename="tickets-', $res->headers->get('Content-Disposition'));
        $this->assertSame('no-store', $res->headers->get('Cache-Control'));
        $this->assertSame('2', $res->headers->get('X-Tickets-Count'));
        $this->assertSame('%PDF-FAKE', $res->getContent());
        foreach ($vouchers as $v) {
            $this->assertStringContainsString($v->code, $this->engine->html);
        }
        $this->assertStringContainsString('<barcode', $this->engine->html);
    }

    public function test_starter_plan_has_no_qr_by_default_and_cannot_force_it(): void
    {
        [, $owner, , , $vouchers] = $this->stock('STARTER', 1);
        Sanctum::actingAs($owner);

        $this->postJson('/api/v1/tickets/pdf', ['format' => 'a5', 'voucher_ids' => [$vouchers[0]->id]])->assertOk();
        $this->assertStringNotContainsString('<barcode', $this->engine->html);

        $calls = $this->engine->calls;
        $this->postJson('/api/v1/tickets/pdf', ['format' => 'a5', 'voucher_ids' => [$vouchers[0]->id], 'include_qr' => true])
            ->assertStatus(403)->assertJsonPath('errors.0.code', 'feature_unavailable');
        $this->assertSame($calls, $this->engine->calls);
    }

    public function test_selection_by_router_and_profile_and_exactly_one_selection_is_required(): void
    {
        [, $owner, $router, $profile, $vouchers] = $this->stock('PRO', 2);
        Sanctum::actingAs($owner);

        $this->postJson('/api/v1/tickets/pdf', ['format' => 'individual', 'router_id' => $router->id, 'profile_id' => $profile->id])
            ->assertOk()->assertHeader('X-Tickets-Count', '2');

        $this->postJson('/api/v1/tickets/pdf', ['format' => 'a4'])->assertStatus(422);
        $this->postJson('/api/v1/tickets/pdf', ['format' => 'a4', 'router_id' => $router->id])->assertStatus(422);
        $this->postJson('/api/v1/tickets/pdf', ['format' => 'a4', 'voucher_ids' => [$vouchers[0]->id], 'router_id' => $router->id, 'profile_id' => $profile->id])->assertStatus(422);
        $this->postJson('/api/v1/tickets/pdf', ['format' => 'poster', 'voucher_ids' => [$vouchers[0]->id]])->assertStatus(422);
        $this->postJson('/api/v1/tickets/pdf', ['format' => 'a4', 'voucher_ids' => ['pas-un-uuid']])->assertStatus(422);
    }

    public function test_only_printable_tickets_are_included(): void
    {
        [$tenant, $owner, $router, $profile, $vouchers] = $this->stock('PRO', 1);
        $revoked = $this->makeVoucher($tenant, $router, $profile, ['status' => 'REVOKED']);
        $used = $this->makeVoucher($tenant, $router, $profile, ['status' => 'USED']);
        $pending = $this->makeVoucher($tenant, $router, $profile, ['status' => 'GENERATED', 'sync_status' => 'PENDING']);
        Sanctum::actingAs($owner);

        $this->postJson('/api/v1/tickets/pdf', ['format' => 'a4', 'router_id' => $router->id, 'profile_id' => $profile->id])->assertOk()->assertHeader('X-Tickets-Count', '1');
        foreach ([$revoked, $used, $pending] as $dead) {
            $this->assertStringNotContainsString($dead->code, $this->engine->html);
        }

        $calls = $this->engine->calls;
        $this->postJson('/api/v1/tickets/pdf', ['format' => 'a4', 'voucher_ids' => [$revoked->id, $used->id]])
            ->assertStatus(422)->assertJsonPath('errors.0.code', 'no_printable_tickets');
        $this->assertSame($calls, $this->engine->calls);
    }

    public function test_size_limit_is_enforced(): void
    {
        config(['documents.max_tickets_per_pdf' => 2]);
        [, $owner, $router, $profile] = $this->stock('PRO', 3);
        Sanctum::actingAs($owner);

        $this->postJson('/api/v1/tickets/pdf', ['format' => 'a4', 'router_id' => $router->id, 'profile_id' => $profile->id])
            ->assertStatus(422)->assertJsonPath('errors.0.code', 'too_many_tickets')->assertJsonPath('meta.max', 2);
    }

    public function test_foreign_tenant_tickets_are_rejected_like_unknown_ones(): void
    {
        [, , , , $vouchers] = $this->stock('PRO', 1);
        [, $intruder] = $this->tenantWithOwner(plan: 'PRO');
        Sanctum::actingAs($intruder);

        $this->postJson('/api/v1/tickets/pdf', ['format' => 'a4', 'voucher_ids' => [$vouchers[0]->id]])->assertStatus(422);
        $this->postJson('/api/v1/tickets/pdf', ['format' => 'a4', 'voucher_ids' => ['00000000-0000-4000-8000-000000000000']])->assertStatus(422);
        $this->assertSame(0, $this->engine->calls);
        $this->assertStringNotContainsString($vouchers[0]->code, $this->engine->html);
    }

    public function test_agents_can_export_thermal_tickets_at_80mm(): void
    {
        [$tenant, , , , $vouchers] = $this->stock('PRO', 1);
        $agent = $this->agentFor($tenant);
        Sanctum::actingAs($agent);

        $this->postJson('/api/v1/tickets/pdf', ['format' => 'thermal', 'thermal_width' => 80, 'voucher_ids' => [$vouchers[0]->id]])->assertOk();
        $this->assertSame(80, $this->engine->page['page_w']);
    }

    public function test_every_format_and_language_renders_through_the_engine(): void
    {
        [, $owner, $router, $profile] = $this->stock('PRO', 2);
        Sanctum::actingAs($owner);

        foreach (['a4', 'a5', 'individual', 'thermal'] as $format) {
            foreach (['fr', 'en', 'ar', 'ha'] as $locale) {
                $this->postJson('/api/v1/tickets/pdf', ['format' => $format, 'locale' => $locale, 'router_id' => $router->id, 'profile_id' => $profile->id])->assertOk();
                $this->assertSame($locale, $this->engine->locale);
            }
        }
        $this->postJson('/api/v1/tickets/pdf', ['format' => 'a4', 'locale' => 'ar', 'router_id' => $router->id, 'profile_id' => $profile->id])->assertOk();
        $this->assertStringContainsString('dir="rtl"', $this->engine->html);
        $this->assertStringContainsString('الرمز', $this->engine->html);

        $this->postJson('/api/v1/tickets/pdf', ['format' => 'a4', 'locale' => 'ha', 'router_id' => $router->id, 'profile_id' => $profile->id])->assertOk();
        $this->assertStringContainsString('Lamba', $this->engine->html);
        $this->postJson('/api/v1/tickets/pdf', ['format' => 'a4', 'locale' => 'de', 'router_id' => $router->id, 'profile_id' => $profile->id])->assertStatus(422);
    }

    public function test_user_supplied_text_cannot_inject_markup_into_the_pdf(): void
    {
        [$tenant, $owner, $router] = $this->stock('PRO', 0);
        $evilPlan = $this->makeProfile($tenant, ['name' => '<img src="http://evil.example/x.png"><b>PLAN']);
        $voucher = $this->makeVoucher($tenant, $router, $evilPlan);
        Sanctum::actingAs($owner);

        $this->putJson('/api/v1/branding', [
            'display_name' => '<script>alert(1)</script>Boutique',
            'slogan' => '"><iframe src="http://evil.example">',
            'terms' => "Ligne 1\n<style>body{display:none}</style>",
            'phone' => '+227 90 00 00 00',
        ])->assertOk();

        $this->postJson('/api/v1/tickets/pdf', ['format' => 'a4', 'voucher_ids' => [$voucher->id]])->assertOk();
        $html = $this->engine->html;

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<iframe', $html);
        $this->assertStringNotContainsString('<img src="http', $html);
        $this->assertStringNotContainsString('<b>PLAN', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;Boutique', $html);
        $this->assertStringContainsString('&lt;iframe', $html);
        $this->assertStringContainsString('Ligne 1', $html);
        $this->assertSame(1, substr_count($html, '<style>'), 'seul le style du document est présent');
    }

    public function test_qr_carries_only_the_code_or_the_owners_login_url(): void
    {
        [, $owner, $router, $profile, $vouchers] = $this->stock('PRO', 1);
        Sanctum::actingAs($owner);

        $this->postJson('/api/v1/tickets/pdf', ['format' => 'a4', 'voucher_ids' => [$vouchers[0]->id]])->assertOk();
        $this->assertStringContainsString('<barcode code="'.$vouchers[0]->code.'"', $this->engine->html);

        $this->putJson('/api/v1/branding', ['portal_url' => 'http://hotspot.local/login'])->assertOk();
        $this->postJson('/api/v1/tickets/pdf', ['format' => 'a4', 'voucher_ids' => [$vouchers[0]->id]])->assertOk();
        $this->assertStringContainsString('http://hotspot.local/login?username='.$vouchers[0]->code.'&amp;password='.$vouchers[0]->code, $this->engine->html);
        $this->assertStringNotContainsString('wza_', $this->engine->html);
    }

    public function test_audit_records_the_count_never_the_codes(): void
    {
        [, $owner, , , $vouchers] = $this->stock('PRO', 2);
        Sanctum::actingAs($owner);

        $this->postJson('/api/v1/tickets/pdf', ['format' => 'a5', 'voucher_ids' => [$vouchers[0]->id, $vouchers[1]->id]])->assertOk();

        $log = AuditLog::where('action', 'tickets.exported')->firstOrFail();
        $this->assertSame(2, $log->metadata['count']);
        $this->assertStringNotContainsString($vouchers[0]->code, json_encode($log->metadata));
    }

    public function test_uploaded_logo_is_embedded_in_the_tickets(): void
    {
        Storage::fake('local');
        [, $owner, , , $vouchers] = $this->stock('PRO', 1);
        Sanctum::actingAs($owner);

        $this->post('/api/v1/branding/logo', ['logo' => UploadedFile::fake()->image('logo.png', 300, 200)], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.has_logo', true);
        $this->postJson('/api/v1/tickets/pdf', ['format' => 'a4', 'voucher_ids' => [$vouchers[0]->id]])->assertOk();

        $this->assertStringContainsString('<img src="data:image/png;base64,', $this->engine->html);
    }
}
