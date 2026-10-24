<?php

namespace Tests\Unit;

use App\Domain\Documents\Brand;
use App\Domain\Portal\ExternalUrl;
use App\Domain\Portal\PortalLabels;
use App\Domain\Portal\PortalOrderStatus;
use App\Domain\Portal\PortalPageBuilder;
use App\Domain\Portal\PortalSlug;
use PHPUnit\Framework\TestCase;

class PortalDomainTest extends TestCase
{
    public function test_slug_and_token_shapes(): void
    {
        $slug = PortalSlug::generate();
        $this->assertSame(16, strlen($slug));
        $this->assertTrue(PortalSlug::isValid($slug));
        $this->assertNotSame($slug, PortalSlug::generate());
        $this->assertSame(1, preg_match('/^[a-z0-9]{32}$/', PortalSlug::token()));
        foreach (['', 'short', 'UPPERCASE12345', '../etc/passwd', str_repeat('a', 25)] as $bad) {
            $this->assertFalse(PortalSlug::isValid($bad), $bad);
        }
    }

    public function test_labels_have_the_same_keys_in_every_language(): void
    {
        $all = PortalLabels::all();
        $keys = array_keys($all['fr']);
        foreach (PortalLabels::LOCALES as $l) {
            $this->assertSame([], array_diff($keys, array_keys($all[$l])), $l);
            $this->assertSame([], array_diff(array_keys($all[$l]), $keys), $l);
        }
        $this->assertSame('fr', PortalLabels::normalize('zz'));
        $this->assertSame('ar', PortalLabels::normalize('AR-SA'));
    }

    public function test_external_urls(): void
    {
        $this->assertTrue(ExternalUrl::isValid('https://www.starlink.com/account'));
        foreach (['http://a.com', 'https://u:p@a.com', 'javascript:alert(1)', 'https://localhost', 'https://a.com/"x', '', null, str_repeat('a', 301)] as $bad) {
            $this->assertFalse(ExternalUrl::isValid($bad), (string) $bad);
        }
    }

    public function test_order_status_rules(): void
    {
        $this->assertTrue(PortalOrderStatus::canFulfill(PortalOrderStatus::PENDING_PAYMENT));
        $this->assertTrue(PortalOrderStatus::canFulfill(PortalOrderStatus::EXPIRED));
        $this->assertFalse(PortalOrderStatus::canFulfill(PortalOrderStatus::FAILED));
        $this->assertFalse(PortalOrderStatus::canFulfill(PortalOrderStatus::FULFILLED));
    }

    public function test_page_never_embeds_owner_text_as_html(): void
    {
        $brand = new Brand('<script>x</script>', '+227', '"><img src=x onerror=1>', "a\n</script>", '#102030');
        $js = (string) file_get_contents(__DIR__.'/../../resources/portal/portal.js');
        $html = PortalPageBuilder::build('abcdefgh2345', $brand, 'ha', 'https://x.test/api/v1', true, $js);

        $this->assertStringNotContainsString('<script>x', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertSame(2, substr_count($html, '<script>'), 'CONFIG + portail.js uniquement');
        $this->assertStringContainsString('dir="ltr"', $html);
        $this->assertStringContainsString('src="https://x.test/api/v1/portal/abcdefgh2345/logo"', $html);
        $this->assertStringNotContainsString('innerHTML', $js, 'le script du portail ne doit jamais utiliser innerHTML');
        $this->assertStringNotContainsString('</script', $js);
    }
}
