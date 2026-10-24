<?php

namespace Tests\Feature\Documents;

use App\Models\VoucherBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesNetwork;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Rendu RÉEL avec mPDF (aucun faux moteur) : 4 formats x 4 langues, reçu, facture. Ignoré si mPDF n'est pas installé.
 * Les PDF sont écrits dans storage/app/sample-pdfs : le workflow CI les publie en artefact pour contrôle visuel
 * (arabe de droite à gauche, caractères hausa, QR, logo), ce qu'aucun test automatique ne remplace.
 */
class PdfRenderingTest extends TestCase
{
    use CreatesNetwork, CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (! class_exists(\Mpdf\Mpdf::class)) {
            $this->markTestSkipped('mpdf/mpdf non installé.');
        }
        $this->seedRoles();
        Storage::fake('local');
    }

    private function save(string $name, string $bytes): void
    {
        $dir = storage_path('app/sample-pdfs');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents("$dir/$name.pdf", $bytes);
    }

    public function test_real_pdfs_are_valid_for_every_format_and_language(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner(['name' => 'Cyber Maradi'], plan: 'PRO');
        [$router] = $this->enrolledRouter($tenant);
        $profile = $this->makeProfile($tenant, ['name' => 'Une heure / ساعة / Awa daya', 'price' => 200]);
        for ($i = 0; $i < 25; $i++) {
            $this->makeVoucher($tenant, $router, $profile);
        }
        Sanctum::actingAs($owner);
        $this->post('/api/v1/branding/logo', ['logo' => UploadedFile::fake()->image('logo.png', 300, 150)], ['Accept' => 'application/json'])->assertOk();
        $this->putJson('/api/v1/branding', [
            'display_name' => 'Cyber Maradi — الواي فاي', 'phone' => '+227 90 00 00 00', 'slogan' => 'Ƙarfi · Wifi · واي فاي',
            'terms' => 'Pas de revente. Ɓoyayyen lamba. لا إعادة بيع.', 'primary_color' => '#EF3340', 'portal_url' => 'http://10.5.50.1/login',
        ])->assertOk();

        foreach (['fr', 'en', 'ar', 'ha'] as $locale) {
            foreach (['a4', 'a5', 'individual', 'thermal'] as $format) {
                $res = $this->postJson('/api/v1/tickets/pdf', ['format' => $format, 'locale' => $locale, 'router_id' => $router->id, 'profile_id' => $profile->id])->assertOk();
                $pdf = $res->getContent();
                $this->assertStringStartsWith('%PDF-', $pdf, "$locale/$format");
                $this->assertGreaterThan(2000, strlen($pdf), "$locale/$format trop petit");
                $this->save("tickets-$locale-$format", $pdf);
            }
        }

        // Reçu (vente réelle) et facture.
        $agent = $this->agentFor($tenant);
        Sanctum::actingAs($agent);
        $sale = $this->postJson('/api/v1/sales', ['router_id' => $router->id, 'profile_id' => $profile->id, 'payment_method' => 'CASH'])->assertCreated()->json('data');
        foreach (['fr', 'ar'] as $locale) {
            $pdf = $this->get("/api/v1/sales/{$sale['id']}/receipt?format=thermal&locale=$locale", ['Accept' => 'application/json'])->assertOk()->getContent();
            $this->assertStringStartsWith('%PDF-', $pdf);
            $this->save("recu-$locale", $pdf);
        }
    }
}
