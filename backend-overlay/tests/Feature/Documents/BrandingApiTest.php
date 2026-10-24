<?php

namespace Tests\Feature\Documents;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class BrandingApiTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        Storage::fake('local');
    }

    private function upload(string $name, string $content): \Illuminate\Testing\TestResponse
    {
        return $this->post('/api/v1/branding/logo', ['logo' => UploadedFile::fake()->createWithContent($name, $content)], ['Accept' => 'application/json']);
    }

    public function test_owner_updates_and_reads_branding(): void
    {
        [, $owner] = $this->tenantWithOwner();
        Sanctum::actingAs($owner);

        $this->getJson('/api/v1/branding')->assertOk()->assertJsonPath('data.has_logo', false)->assertJsonPath('data.display_name', null);
        $this->putJson('/api/v1/branding', [
            'display_name' => 'Cyber Maradi', 'phone' => '+227 90 00 00 00', 'slogan' => 'Le WiFi pour tous',
            'terms' => 'Pas de revente.', 'primary_color' => '#12abef', 'portal_url' => 'http://10.5.50.1/login',
        ])->assertOk()->assertJsonPath('data.primary_color', '#12ABEF')->assertJsonPath('data.display_name', 'Cyber Maradi');
        $this->getJson('/api/v1/branding')->assertOk()->assertJsonPath('data.slogan', 'Le WiFi pour tous');

        $this->putJson('/api/v1/branding', ['slogan' => null])->assertOk()->assertJsonPath('data.slogan', null)->assertJsonPath('data.display_name', 'Cyber Maradi');
    }

    public function test_invalid_values_are_rejected(): void
    {
        [, $owner] = $this->tenantWithOwner();
        Sanctum::actingAs($owner);

        foreach ([
            ['primary_color' => 'red'], ['primary_color' => '#12345'], ['primary_color' => '#123456;}</style>'],
            ['portal_url' => 'javascript:alert(1)'], ['portal_url' => 'ftp://x/y'], ['portal_url' => 'http://a b/c'], ['portal_url' => 'http://x/"><script>'],
            ['phone' => '<script>'], ['display_name' => str_repeat('a', 81)], ['terms' => str_repeat('a', 601)],
        ] as $bad) {
            $this->putJson('/api/v1/branding', $bad)->assertStatus(422);
        }
    }

    public function test_agents_cannot_change_branding_but_can_read_it(): void
    {
        [$tenant] = $this->tenantWithOwner();
        Sanctum::actingAs($this->agentFor($tenant));

        $this->getJson('/api/v1/branding')->assertOk();
        $this->putJson('/api/v1/branding', ['display_name' => 'X'])->assertStatus(403);
        $this->upload('logo.png', 'x')->assertStatus(403);
        $this->deleteJson('/api/v1/branding/logo')->assertStatus(403);
    }

    public function test_logo_is_reencoded_stored_privately_with_a_random_name_and_served_as_png(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner();
        Sanctum::actingAs($owner);

        $this->post('/api/v1/branding/logo', ['logo' => UploadedFile::fake()->image('evil.php.png', 1200, 800)], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.has_logo', true);

        $files = Storage::disk('local')->allFiles('branding');
        $this->assertCount(1, $files);
        $this->assertMatchesRegularExpression('#^branding/[0-9a-f-]{36}\.png$#', $files[0]);
        $this->assertStringNotContainsString('evil', $files[0]);

        $res = $this->get('/api/v1/branding/logo', ['Accept' => 'application/json'])->assertOk();
        $this->assertSame('image/png', $res->headers->get('Content-Type'));
        $this->assertSame('nosniff', $res->headers->get('X-Content-Type-Options'));
        $size = getimagesizefromstring($res->getContent());
        $this->assertSame(512, $size[0]);                      // réduit à 512 px
        $this->assertSame(IMAGETYPE_PNG, $size[2]);

        // Un second logo remplace le premier (pas d'accumulation de fichiers).
        $this->post('/api/v1/branding/logo', ['logo' => UploadedFile::fake()->image('b.png', 100, 100)], ['Accept' => 'application/json'])->assertOk();
        $this->assertCount(1, Storage::disk('local')->allFiles('branding'));

        $this->deleteJson('/api/v1/branding/logo')->assertOk()->assertJsonPath('data.has_logo', false);
        $this->assertCount(0, Storage::disk('local')->allFiles('branding'));
        $this->getJson('/api/v1/branding/logo')->assertStatus(404);
    }

    public function test_dangerous_or_fake_images_are_refused(): void
    {
        [, $owner] = $this->tenantWithOwner();
        Sanctum::actingAs($owner);

        $this->upload('logo.png', '<?php system($_GET["c"]); ?>')->assertStatus(422)->assertJsonPath('errors.0.code', 'logo_invalid');
        $this->upload('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')->assertStatus(422)->assertJsonPath('errors.0.code', 'logo_invalid');
        $this->upload('logo.png', "GIF89a\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\xFF\xFF\xFF,\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02D\x01\x00;")->assertStatus(422);
        $this->post('/api/v1/branding/logo', ['logo' => UploadedFile::fake()->image('big.png', 5000, 40)], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonPath('errors.0.code', 'logo_dimensions');
        $this->post('/api/v1/branding/logo', [], ['Accept' => 'application/json'])->assertStatus(422);
        $this->assertCount(0, Storage::disk('local')->allFiles('branding'));
    }

    public function test_branding_and_logo_are_isolated_between_tenants(): void
    {
        [, $a] = $this->tenantWithOwner();
        [, $b] = $this->tenantWithOwner();

        Sanctum::actingAs($a);
        $this->putJson('/api/v1/branding', ['display_name' => 'Chez A'])->assertOk();
        $this->post('/api/v1/branding/logo', ['logo' => UploadedFile::fake()->image('a.png', 100, 100)], ['Accept' => 'application/json'])->assertOk();

        Sanctum::actingAs($b);
        $this->getJson('/api/v1/branding')->assertOk()->assertJsonPath('data.display_name', null)->assertJsonPath('data.has_logo', false);
        $this->getJson('/api/v1/branding/logo')->assertStatus(404);
    }

    public function test_writes_are_blocked_when_subscription_is_inactive_but_reading_works(): void
    {
        [, $owner] = $this->tenantWithOwner(trial: false);
        Sanctum::actingAs($owner);

        $this->getJson('/api/v1/branding')->assertOk();
        $this->putJson('/api/v1/branding', ['display_name' => 'X'])->assertStatus(402);
    }
}
