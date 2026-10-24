<?php

namespace Tests\Unit;

use App\Domain\Documents\Brand;
use App\Domain\Documents\CsvWriter;
use App\Domain\Documents\DocumentLabels;
use App\Domain\Documents\DurationFormatter;
use App\Domain\Documents\HexColor;
use App\Domain\Documents\Html;
use App\Domain\Documents\InvoiceHtmlBuilder;
use App\Domain\Documents\LogoProcessor;
use App\Domain\Documents\MoneyFormatter;
use App\Domain\Documents\ReceiptHtmlBuilder;
use App\Domain\Documents\TicketData;
use App\Domain\Documents\TicketHtmlBuilder;
use App\Domain\Documents\TicketLayout;
use App\Domain\Documents\TicketQr;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class DocumentsDomainTest extends TestCase
{
    private function ticket(string $code = 'ABCD2345', string $plan = '1 heure'): TicketData
    {
        return new TicketData($code, $plan, "200\u{00A0}FCFA", 60, null, 1, '2M/2M', false, $code);
    }

    public function test_every_label_exists_in_every_language(): void
    {
        foreach (DocumentLabels::LOCALES as $locale) {
            foreach (DocumentLabels::keys() as $key) {
                $this->assertTrue(DocumentLabels::has($locale, $key), "$locale/$key");
            }
        }
        $this->assertSame('fr', DocumentLabels::normalize('zz'));
        $this->assertTrue(DocumentLabels::isRtl('ar'));
        $this->assertFalse(DocumentLabels::isRtl('ha'));
    }

    public function test_money_duration_and_color_helpers(): void
    {
        $this->assertSame("12\u{00A0}500\u{00A0}FCFA", MoneyFormatter::format(12500, 0, 'FCFA'));
        $this->assertSame("1\u{00A0}234.56\u{00A0}€", MoneyFormatter::format(123456, 2, '€'));
        $this->assertSame('1 h 30 min', DurationFormatter::format(90, 'fr'));
        $this->assertSame('7 d', DurationFormatter::format(10080, 'en'));
        $this->assertSame('Illimitée', DurationFormatter::format(null, 'fr'));
        $this->assertSame(HexColor::DEFAULT, HexColor::normalize('#12345;}'));
        $this->assertSame('#FFFFFF', HexColor::contrastText('#102030'));
    }

    public function test_layout_geometry_fits_the_page_for_every_format(): void
    {
        foreach ([['a4', 58], ['a5', 58], ['individual', 58], ['thermal', 58], ['thermal', 80]] as [$format, $width]) {
            $l = TicketLayout::for($format, $width);
            $this->assertLessThanOrEqual($l['page_w'] - 2 * $l['margin'] + 0.001, $l['cols'] * $l['card_w'], "$format largeur");
            $this->assertLessThanOrEqual($l['page_h'] - 2 * $l['margin'], $l['rows'] * $l['card_h'], "$format hauteur");
            $this->assertSame($l['cols'] * $l['rows'], $l['per_page']);
            $this->assertGreaterThan(0, $l['card_w']);
        }
        $this->assertSame(21, TicketLayout::for('a4')['per_page']);
        $this->assertSame(1, TicketLayout::for('thermal')['per_page']);
        $this->assertSame(80, TicketLayout::for('thermal', 80)['page_w']);
        $this->expectException(InvalidArgumentException::class);
        TicketLayout::for('poster');
    }

    public function test_ticket_sheet_paginates_and_pads_the_last_row(): void
    {
        $tickets = array_map(fn ($i) => $this->ticket(sprintf('CODE%04d', $i)), range(1, 25));
        $html = TicketHtmlBuilder::build($tickets, TicketLayout::for('a4'), 'fr', new Brand('Boutique'), true);

        $this->assertSame(1, substr_count($html, '<pagebreak />'));
        $this->assertSame(25, substr_count($html, 'class="cell"'));
        $this->assertSame(2, substr_count($html, 'class="empty"'));
        $this->assertSame(25, substr_count($html, '<barcode'));
        $noQr = TicketHtmlBuilder::build($tickets, TicketLayout::for('a4'), 'fr', new Brand('Boutique'), false);
        $this->assertSame(0, substr_count($noQr, '<barcode'));
    }

    public function test_html_escaping_and_hardened_brand(): void
    {
        $brand = new Brand('<b>X</b>', slogan: '"><script>', terms: "a\n<i>b", color: 'red;}</style><script>', portalUrl: 'javascript:alert(1)', logoPngBase64: '"><script>');
        $this->assertSame(HexColor::DEFAULT, $brand->color);
        $this->assertNull($brand->portalUrl);
        $this->assertNull($brand->logoPngBase64);

        $html = TicketHtmlBuilder::build([$this->ticket('ABCD2345', '<img src=x onerror=alert(1)>')], TicketLayout::for('individual'), 'en', $brand, true);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('<b>X', $html);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
        $this->assertStringContainsString('<br', Html::multiline("a\nb"));
        $this->assertSame('ab', Html::e("a\x00b"));
    }

    public function test_receipt_masks_code_and_invoice_builds(): void
    {
        $this->assertSame('•••••345', ReceiptHtmlBuilder::mask('ABCDE345'));
        $r = ['number' => 'ABC12345', 'date_text' => '18/10/2026 10:00', 'seller' => 'Aïcha', 'plan' => '1 h', 'duration_minutes' => 60, 'amount_text' => '200 FCFA',
            'payment_method' => 'CASH', 'reference' => null, 'code' => 'ABCDE345', 'voided' => true];
        $html = ReceiptHtmlBuilder::build($r, 'ar', new Brand('Café'), true);
        $this->assertStringContainsString('dir="rtl"', $html);
        $this->assertStringNotContainsString('ABCDE345', $html);
        $this->assertStringContainsString('ملغى', $html);

        $inv = InvoiceHtmlBuilder::build(['number' => 'INV-1', 'status' => 'PAID', 'plan' => 'PRO', 'cycle' => 'MONTHLY', 'period_text' => 'a – b', 'date_text' => 'd',
            'billed_to' => '<x>', 'subtotal_text' => '5000', 'discount_text' => '500', 'total_text' => '4500', 'paid_text' => 'p'], 'ha');
        $this->assertStringContainsString('Takardar kuɗi', $inv);
        $this->assertStringNotContainsString('<x>', $inv);
    }

    public function test_qr_payload_and_portal_url_validation(): void
    {
        $this->assertSame('ABC123', TicketQr::payload('ABC123', null));
        $this->assertSame('ABC123', TicketQr::payload('ABC123', 'javascript:alert(1)'));
        $this->assertSame('http://h/login?username=ABC123&password=ABC123', TicketQr::payload('ABC123', 'http://h/login'));
        $this->assertSame('http://h/login?x=1&username=ABC123&password=ABC123', TicketQr::payload('ABC123', 'http://h/login?x=1'));
        foreach (['ftp://x', 'http://a b', 'http://x/"y', "http://x/\n", str_repeat('a', 201), ''] as $bad) {
            $this->assertFalse(TicketQr::isValidPortalUrl($bad), $bad);
        }
    }

    public function test_csv_writer_neutralises_formulas(): void
    {
        $this->assertSame("'=1+1", CsvWriter::cell('=1+1'));
        $this->assertSame("'-2+3", CsvWriter::cell('-2+3'));
        $this->assertSame('-1500', CsvWriter::cell(-1500));
        $this->assertSame("a;1\r\n", CsvWriter::row(['a', 1]));
        $this->assertSame('"dit ""oui"""', CsvWriter::cell('dit "oui"'));
    }

    public function test_logo_processor_rejects_and_reencodes(): void
    {
        $img = imagecreatetruecolor(800, 400);
        ob_start();
        imagepng($img);
        $png = (string) ob_get_clean();

        $out = LogoProcessor::process($png.'<?php evil(); ?>TRAILER');
        $this->assertSame(512, $out['width']);
        $this->assertStringNotContainsString('TRAILER', $out['png']);

        foreach (['', 'texte', str_repeat('A', LogoProcessor::MAX_BYTES + 1)] as $bad) {
            try {
                LogoProcessor::process($bad);
                $this->fail('doit être refusé');
            } catch (InvalidArgumentException $e) {
                $this->assertContains($e->getMessage(), ['logo_too_large', 'logo_invalid']);
            }
        }
    }
}
