<?php

/**
 * Smoke test SANS Laravel ni Composer : exécute les classes de domaine pures et le fournisseur mock.
 *   php scripts/smoke-domain.php
 * Ne remplace pas la suite PHPUnit (make test) : il prouve seulement que la logique pure fonctionne.
 */
spl_autoload_register(function (string $class): void {
    if (str_starts_with($class, 'App\\')) {
        $file = __DIR__.'/../backend-overlay/app/'.str_replace('\\', '/', substr($class, 4)).'.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

use App\Domain\Billing\DiscountCalculator;
use App\Domain\Billing\PromotionType;
use App\Domain\Money\Money;
use App\Domain\Network\RouterOsValue;
use App\Domain\Network\VoucherStatus;
use App\Domain\Business\CashType;
use App\Domain\Documents\CsvWriter;
use App\Domain\Documents\DocumentLabels;
use App\Domain\Documents\DurationFormatter;
use App\Domain\Documents\HexColor;
use App\Domain\Documents\LogoProcessor;
use App\Domain\Documents\MoneyFormatter;
use App\Domain\Business\PaymentMethod;
use App\Domain\Business\ReportPeriod;
use App\Domain\Payments\Exceptions\InvalidWebhookException;
use App\Domain\Payments\Exceptions\ProviderNotConfiguredException;
use App\Domain\Payments\PaymentStatus;
use App\Domain\Payments\Signature\HmacSignatureVerifier;
use App\Infrastructure\Payments\AirtelMoneyAdapter;
use App\Infrastructure\Payments\MockPaymentProvider;

$failures = 0;
$count = 0;
function check(string $name, bool $ok): void
{
    global $failures, $count;
    $count++;
    if (! $ok) {
        $failures++;
        echo "ÉCHEC : $name\n";
    }
}
function throws(callable $fn, string $class): bool
{
    try {
        $fn();
    } catch (Throwable $e) {
        return $e instanceof $class;
    }

    return false;
}

// --- Money
$p = Money::of(5000, 'xof');
check('devise normalisée en majuscules', $p->currency === 'XOF');
check('add', $p->add(Money::of(1000, 'XOF'))->amount === 6000);
check('subtract', $p->subtract(Money::of(1000, 'XOF'))->amount === 4000);
check('subtract négatif interdit', throws(fn () => Money::of(1, 'XOF')->subtract($p), InvalidArgumentException::class));
check('devises différentes interdites', throws(fn () => $p->add(Money::of(1, 'EUR')), InvalidArgumentException::class));
check('montant négatif interdit', throws(fn () => Money::of(-1, 'XOF'), InvalidArgumentException::class));
check('devise invalide', throws(fn () => Money::of(1, 'FCFA'), InvalidArgumentException::class));
check('20% de 5000 = 1000', $p->percentOf(20)->amount === 1000);
check('arrondi inférieur 15% de 999', Money::of(999, 'XOF')->percentOf(15)->amount === 149);
check('equals', $p->equals(Money::of(5000, 'XOF')) && ! $p->equals(Money::of(5000, 'EUR')));

// --- Remises
check('remise pourcentage', DiscountCalculator::discount($p, PromotionType::PERCENTAGE, 20)->amount === 1000);
check('remise fixe plafonnée au prix', DiscountCalculator::discount($p, PromotionType::FIXED, 9000)->amount === 5000);
check('pourcentage > 100 refusé', throws(fn () => DiscountCalculator::discount($p, PromotionType::PERCENTAGE, 101), InvalidArgumentException::class));
check('extension d\'essai sans effet prix', throws(fn () => DiscountCalculator::discount($p, PromotionType::TRIAL_EXTENSION, 7), InvalidArgumentException::class));

// --- Machine à états
check('PENDING -> SUCCEEDED', PaymentStatus::canTransition('PENDING', 'SUCCEEDED'));
check('INITIATED -> SUCCEEDED (webhook en avance)', PaymentStatus::canTransition('INITIATED', 'SUCCEEDED'));
check('EXPIRED -> SUCCEEDED (paiement tardif)', PaymentStatus::canTransition('EXPIRED', 'SUCCEEDED'));
check('FAILED -> SUCCEEDED interdit', ! PaymentStatus::canTransition('FAILED', 'SUCCEEDED'));
check('SUCCEEDED -> PENDING interdit', ! PaymentStatus::canTransition('SUCCEEDED', 'PENDING'));
check('statut inconnu', ! PaymentStatus::canTransition('???', 'SUCCEEDED'));
check('FAILED terminal', PaymentStatus::isTerminal('FAILED') && ! PaymentStatus::isTerminal('PENDING'));

// --- Signature HMAC
$v = new HmacSignatureVerifier(300);
$now = 1_800_000_000;
$sig = $v->sign('s3cret', $now, '{"a":1}');
check('signature valide', $v->verify('s3cret', (string) $now, $sig, '{"a":1}', $now));
check('corps modifié refusé', ! $v->verify('s3cret', (string) $now, $sig, '{"a":2}', $now));
check('mauvais secret refusé', ! $v->verify('autre', (string) $now, $sig, '{"a":1}', $now));
check('horodatage périmé refusé', ! $v->verify('s3cret', (string) $now, $sig, '{"a":1}', $now + 301));
check('horodatage dans la tolérance', $v->verify('s3cret', (string) $now, $sig, '{"a":1}', $now + 300));
check('secret vide refusé', ! $v->verify('', (string) $now, $v->sign('', $now, '{"a":1}'), '{"a":1}', $now));
check('horodatage non numérique refusé', ! $v->verify('s3cret', 'abc', $sig, '{"a":1}', $now));

// --- Fournisseur mock
$mock = new MockPaymentProvider('mock-secret', true, new HmacSignatureVerifier(300));
$ts = time();
$body = json_encode(['event_id' => 'evt_1', 'type' => 'payment.succeeded', 'reference' => 'mock_abc', 'amount' => 5000, 'currency' => 'XOF']);
$headers = ['x-mock-timestamp' => (string) $ts, 'x-mock-signature' => (new HmacSignatureVerifier)->sign('mock-secret', $ts, $body)];
$event = $mock->parseWebhook($body, $headers);
check('webhook mock valide', $event->status === 'SUCCEEDED' && $event->providerReference === 'mock_abc' && $event->amount?->amount === 5000);
check('webhook mock signature fausse', throws(fn () => $mock->parseWebhook($body, ['x-mock-timestamp' => (string) $ts, 'x-mock-signature' => 'sha256=00']), InvalidWebhookException::class));
check('webhook mock sans en-têtes', throws(fn () => $mock->parseWebhook($body, []), InvalidWebhookException::class));
$badBody = json_encode(['event_id' => 'e', 'type' => 'refund.created', 'reference' => 'r']);
check('type inconnu refusé', throws(fn () => $mock->parseWebhook($badBody, ['x-mock-timestamp' => (string) $ts, 'x-mock-signature' => (new HmacSignatureVerifier)->sign('mock-secret', $ts, $badBody)]), InvalidWebhookException::class));
$negBody = json_encode(['event_id' => 'e', 'type' => 'payment.failed', 'reference' => 'r', 'amount' => -5, 'currency' => 'XOF']);
check('montant négatif refusé', throws(fn () => $mock->parseWebhook($negBody, ['x-mock-timestamp' => (string) $ts, 'x-mock-signature' => (new HmacSignatureVerifier)->sign('mock-secret', $ts, $negBody)]), InvalidWebhookException::class));
$disabled = new MockPaymentProvider('mock-secret', false, new HmacSignatureVerifier);
check('mock désactivé inutilisable', ! $disabled->isConfigured() && throws(fn () => $disabled->parseWebhook($body, $headers), ProviderNotConfiguredException::class));
check('mock sans secret inutilisable', ! (new MockPaymentProvider('', true, new HmacSignatureVerifier))->isConfigured());

// --- Adaptateur non branché
$airtel = new AirtelMoneyAdapter;
check('airtel non branché', ! $airtel->isConfigured() && throws(fn () => $airtel->parseWebhook('{}', []), ProviderNotConfiguredException::class));

// --- Réseau (Phase 3) : liste blanche des valeurs envoyées au routeur, machine à états des vouchers
foreach (['512k', '2M', '2M/2M', '1500k/3M'] as $r) {
    check("débit valide $r", RouterOsValue::isValidRateLimit($r));
}
foreach (['2M/2M; /system reset', "2M\n/ip", '$(x)', '2T', '', '2M/2M/2M'] as $r) {
    check('débit refusé '.json_encode($r), ! RouterOsValue::isValidRateLimit($r));
}
check('code valide', RouterOsValue::isValidCode('ABCDEF23'));
check('code avec saut de ligne refusé', ! RouterOsValue::isValidCode("ABCDEF23\n"));
check('code ambigu refusé', ! RouterOsValue::isValidCode('OOO000II'));
$codesOk = true;
for ($i = 0; $i < 100; $i++) {
    $codesOk = $codesOk && RouterOsValue::isValidCode(RouterOsValue::randomCode(8));
}
check('codes aléatoires conformes', $codesOk);
check('nom de profil dérivé de l\'UUID', RouterOsValue::profileName('0192f5a0-7b1c-7d3e-8a4f-123456789abc') === 'wz-56789abc');
check('GENERATED -> AVAILABLE', VoucherStatus::canTransition(VoucherStatus::GENERATED, VoucherStatus::AVAILABLE));
check('AVAILABLE -> SOLD -> ACTIVE -> USED', VoucherStatus::canTransition('AVAILABLE', 'SOLD') && VoucherStatus::canTransition('SOLD', 'ACTIVE') && VoucherStatus::canTransition('ACTIVE', 'USED'));
check('retour arrière interdit', ! VoucherStatus::canTransition('SOLD', 'AVAILABLE'));
$terminalOk = true;
foreach ([VoucherStatus::USED, VoucherStatus::EXPIRED, VoucherStatus::REVOKED] as $t) {
    foreach (VoucherStatus::ALL as $to) {
        $terminalOk = $terminalOk && ! VoucherStatus::canTransition($t, $to);
    }
}
check('états terminaux figés', $terminalOk);

// --- Business (Phase 4) : sens des écritures de caisse, ventilation des rapports
check('CASH_IN/SALE/OPEN en entrée', CashType::signed('CASH_IN', 100) === 100 && CashType::signed('SALE', 100) === 100 && CashType::signed('OPEN', 100) === 100);
check('EXPENSE/WITHDRAWAL en sortie même saisis positifs', CashType::signed('EXPENSE', 100) === -100 && CashType::signed('WITHDRAWAL', 100) === -100);
check('une sortie saisie négative reste une sortie (pas de double négation)', CashType::signed('EXPENSE', -100) === -100);
check('ADJUSTMENT signé librement', CashType::signed('ADJUSTMENT', -30) === -30 && CashType::signed('ADJUSTMENT', 30) === 30);
check('moyens de paiement connus', PaymentMethod::ALL === ['CASH', 'MOBILE_MONEY', 'BANK', 'OTHER']);
check('plages maximales de rapport', ReportPeriod::maxDays('daily') === 366 && ReportPeriod::maxDays('monthly') === 1830);
if (class_exists(\Carbon\CarbonImmutable::class)) {
    check('seau hebdomadaire = lundi ISO', ReportPeriod::bucket('weekly', '2026-10-11') === '2026-10-05');
    check('seau mensuel/annuel', ReportPeriod::bucket('monthly', '2026-10-20') === '2026-10-01' && ReportPeriod::bucket('annual', '2026-10-20') === '2026-01-01');
} else {
    echo "  (Carbon absent : seaux de rapport vérifiés par PHPUnit uniquement)\n";
}

// --- Documents (Phase 5) : libellés, montants, durées, CSV, couleur, logo
$labelsOk = true;
foreach (DocumentLabels::LOCALES as $loc) {
    foreach (DocumentLabels::keys() as $key) {
        $labelsOk = $labelsOk && DocumentLabels::has($loc, $key);
    }
}
check('toutes les clés de libellé existent dans les 4 langues', $labelsOk);
check('langue inconnue -> français', DocumentLabels::normalize('de') === 'fr' && DocumentLabels::normalize('AR-SA') === 'ar' && DocumentLabels::normalize(null) === 'fr');
check('seul l\'arabe est RTL', DocumentLabels::isRtl('ar') && ! DocumentLabels::isRtl('fr') && ! DocumentLabels::isRtl('ha'));
check('hausa : caractères crochets conservés', str_contains(DocumentLabels::get('ha', 'amount').DocumentLabels::get('ha', 'payment'), 'ɗ'));
check('montant XOF', MoneyFormatter::format(12500, 0, 'FCFA') === "12\u{00A0}500\u{00A0}FCFA");
check('montant 2 décimales', MoneyFormatter::format(1050, 2, '$') === "10.50\u{00A0}$" && MoneyFormatter::format(5, 2, '€') === "0.05\u{00A0}€");
check('montant négatif et zéro', MoneyFormatter::format(-1500, 0, 'FCFA') === "-1\u{00A0}500\u{00A0}FCFA" && MoneyFormatter::format(0, 0, 'FCFA') === "0\u{00A0}FCFA");
check('durées', DurationFormatter::format(30, 'fr') === '30 min' && DurationFormatter::format(60, 'fr') === '1 h' && DurationFormatter::format(1440, 'en') === '1 d' && DurationFormatter::format(90, 'fr') === '1 h 30 min' && DurationFormatter::format(10080, 'fr') === '7 j');
check('durée illimitée', DurationFormatter::format(null, 'en') === 'Unlimited');
check('CSV : formule neutralisée', CsvWriter::cell('=HYPERLINK("x")') === "\"'=HYPERLINK(\"\"x\"\")\"" && CsvWriter::cell('+1') === "'+1" && CsvWriter::cell('@SUM(A1)') === "'@SUM(A1)");
check('CSV : entier négatif intact, null vide', CsvWriter::cell(-1500) === '-1500' && CsvWriter::cell(null) === '');
check('CSV : séparateur et guillemets protégés', CsvWriter::cell('a;b') === '"a;b"' && CsvWriter::cell('dit "oui"') === '"dit ""oui"""' && CsvWriter::row(['a', 1]) === "a;1\r\n");
check('couleur valide / invalide', HexColor::isValid('#12abEF') && ! HexColor::isValid('red') && ! HexColor::isValid('#12345') && ! HexColor::isValid('#123456;}body{x:y'));
check('couleur invalide -> défaut', HexColor::normalize('url(x)') === HexColor::DEFAULT && HexColor::normalize('#abcdef') === '#ABCDEF');
check('contraste du texte', HexColor::contrastText('#FFFFFF') === '#000000' && HexColor::contrastText('#000000') === '#FFFFFF' && HexColor::contrastText('#EF3340') === '#000000');

if (extension_loaded('gd')) {
    $img = imagecreatetruecolor(800, 400);
    imagefill($img, 0, 0, imagecolorallocate($img, 200, 30, 30));
    ob_start(); imagepng($img); $png = (string) ob_get_clean();
    $evil = $png.'<?php system("id"); ?>MARKER-APPENDED';
    $out = LogoProcessor::process($evil);
    check('logo : PNG ré-encodé, redimensionné à 512 px', $out['width'] === 512 && $out['height'] === 256 && str_starts_with($out['png'], "\x89PNG"));
    check('logo : données ajoutées supprimées', ! str_contains($out['png'], 'MARKER-APPENDED') && ! str_contains($out['png'], '<?php'));
    $reject = function (string $bytes): string {
        try { LogoProcessor::process($bytes); return 'accepté'; } catch (InvalidArgumentException $e) { return $e->getMessage(); }
    };
    check('logo : texte refusé', $reject('<?php echo 1;') === 'logo_invalid');
    check('logo : SVG refusé', $reject('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>') === 'logo_invalid');
    ob_start(); imagegif($img); $gif = (string) ob_get_clean();
    check('logo : GIF refusé', $reject($gif) === 'logo_unsupported_type');
    check('logo : trop gros refusé', $reject(str_repeat('A', LogoProcessor::MAX_BYTES + 1)) === 'logo_too_large' && $reject('') === 'logo_too_large');
    $wide = imagecreatetruecolor(4001, 20);
    ob_start(); imagepng($wide); $widePng = (string) ob_get_clean();
    check('logo : dimensions excessives refusées', $reject($widePng) === 'logo_dimensions');
    $tiny = imagecreatetruecolor(8, 8);
    ob_start(); imagepng($tiny); $tinyPng = (string) ob_get_clean();
    check('logo : trop petit refusé', $reject($tinyPng) === 'logo_dimensions');
} else {
    echo "  (GD absent : tests du logo ignorés)\n";
}

// --- Phase 5 : mise en page et constructeurs HTML ---
$tk = fn (int $i) => new \App\Domain\Documents\TicketData(sprintf('CODE%04d', $i), '1 heure', '200 FCFA', 60, null, 1, '2M/2M', false, sprintf('CODE%04d', $i));
$sheet = \App\Domain\Documents\TicketHtmlBuilder::build(array_map($tk, range(1, 25)), \App\Domain\Documents\TicketLayout::for('a4'), 'fr', new \App\Domain\Documents\Brand('Boutique'), true);
check('tickets A4 : 25 cartes, 1 saut de page, 2 cases vides, 25 QR', substr_count($sheet, '<pagebreak />') === 1 && substr_count($sheet, 'class="cell"') === 25 && substr_count($sheet, 'class="empty"') === 2 && substr_count($sheet, '<barcode') === 25);
$evilBrand = new \App\Domain\Documents\Brand('<b>X</b>', null, '"><script>', null, 'red;}</style><script>', 'javascript:alert(1)', '"><script>');
$evilHtml = \App\Domain\Documents\TicketHtmlBuilder::build([new \App\Domain\Documents\TicketData('ABCD2345', '<img src=x onerror=alert(1)>', '1', 60, null, 1, null, false, 'ABCD2345')], \App\Domain\Documents\TicketLayout::for('individual'), 'en', $evilBrand, true);
check('HTML : aucune injection (script/img/couleur/portail)', ! str_contains($evilHtml, '<script') && ! str_contains($evilHtml, '<img') && ! str_contains($evilHtml, '<b>X') && $evilBrand->color === '#EF3340' && $evilBrand->portalUrl === null);
$rcpt = \App\Domain\Documents\ReceiptHtmlBuilder::build(['number' => 'ABC12345', 'date_text' => 'd', 'seller' => null, 'plan' => '1 h', 'duration_minutes' => 60, 'amount_text' => '200', 'payment_method' => 'CASH', 'reference' => null, 'code' => 'ABCDE345', 'voided' => true], 'ar', new \App\Domain\Documents\Brand('Café'), true);
check('reçu : RTL, code masqué, mention annulée', str_contains($rcpt, 'dir="rtl"') && ! str_contains($rcpt, 'ABCDE345') && str_contains($rcpt, 'ملغى'));
foreach ([['a4', 58], ['a5', 58], ['individual', 58], ['thermal', 58], ['thermal', 80]] as [$f, $w]) {
    $l = \App\Domain\Documents\TicketLayout::for($f, $w);
    check("mise en page $f/$w tient dans la page", $l['cols'] * $l['card_w'] <= $l['page_w'] - 2 * $l['margin'] + 0.001 && $l['rows'] * $l['card_h'] <= $l['page_h'] - 2 * $l['margin']);
}

// --- Phase 6 : taux de change indicatifs (entiers, sans float) ---
use App\Domain\Money\ExchangeRate;
check('taux : analyse et format', ExchangeRate::parse('655.957') === 65595700000 && ExchangeRate::format(65595700000) === '655.957');
check('taux : 10,00 EUR -> 6 560 XOF', ExchangeRate::convert(1000, 2, 0, ExchangeRate::parse('655.957')) === 6560);
check('taux : 1 000 000 XOF -> 1 524,49 EUR', ExchangeRate::convert(1000000, 0, 2, ExchangeRate::parse('0.00152449')) === 152449);
check('taux : arrondi demi-unité', ExchangeRate::convert(1, 0, 0, ExchangeRate::parse('0.4')) === 0 && ExchangeRate::convert(1, 0, 0, ExchangeRate::parse('0.5')) === 1);
check('taux : grand montant sans dépassement', ExchangeRate::convert(99999999999, 0, 0, ExchangeRate::parse('9999999')) === 999999899990000001);
$badRates = 0;
foreach (['0', '-1', '1e3', 'abc', '', '1.123456789', '12345678'] as $bad) {
    try { ExchangeRate::parse($bad); } catch (InvalidArgumentException) { $badRates++; }
}
check('taux : entrées invalides refusées', $badRates === 7);

// --- Phase 7 : portail captif ---
$slug = \App\Domain\Portal\PortalSlug::generate();
check('portail : slug 16 car. valide, jeton 32 car.', strlen($slug) === 16 && \App\Domain\Portal\PortalSlug::isValid($slug) && preg_match('/^[a-z0-9]{32}$/', \App\Domain\Portal\PortalSlug::token()) === 1 && ! \App\Domain\Portal\PortalSlug::isValid('../etc/passwd'));
$pl = \App\Domain\Portal\PortalLabels::all();
$same = true;
foreach (\App\Domain\Portal\PortalLabels::LOCALES as $loc) { $same = $same && array_keys($pl[$loc]) === array_keys($pl['fr']); }
check('portail : mêmes clés de texte en fr/en/ar/ha', $same);
check('liens externes : https seulement, sans identifiants', \App\Domain\Portal\ExternalUrl::isValid('https://www.starlink.com/account') && ! \App\Domain\Portal\ExternalUrl::isValid('http://a.com') && ! \App\Domain\Portal\ExternalUrl::isValid('https://u:p@a.com') && ! \App\Domain\Portal\ExternalUrl::isValid('javascript:alert(1)'));
$portalJs = (string) file_get_contents(__DIR__.'/../backend-overlay/resources/portal/portal.js');
$page = \App\Domain\Portal\PortalPageBuilder::build('abcdefgh2345', new \App\Domain\Documents\Brand('<script>x</script>', null, '"><img src=x>', null, '#102030'), 'ar', 'https://x.test/api/v1', false, $portalJs);
check('portail : page sans injection, RTL en arabe, 2 scripts seulement', ! str_contains($page, '<script>x') && ! str_contains($page, '<img src=x') && str_contains($page, 'dir="rtl"') && substr_count($page, '<script>') === 2);
check('portail : script sans innerHTML ni </script', ! str_contains($portalJs, 'innerHTML') && ! str_contains($portalJs, '</script'));
check('commande portail : livrable si en attente ou expirée seulement', \App\Domain\Portal\PortalOrderStatus::canFulfill('PENDING_PAYMENT') && \App\Domain\Portal\PortalOrderStatus::canFulfill('EXPIRED') && ! \App\Domain\Portal\PortalOrderStatus::canFulfill('FAILED') && ! \App\Domain\Portal\PortalOrderStatus::canFulfill('FULFILLED'));

// --- Phase 7 : portail captif (logique pure) ---
$slug = \App\Domain\Portal\PortalSlug::generate();
check('portail : slug 16 car. valide, jeton 32 car.', strlen($slug) === 16 && \App\Domain\Portal\PortalSlug::isValid($slug) && preg_match('/^[a-z0-9]{32}$/', \App\Domain\Portal\PortalSlug::token()) === 1);
check('portail : slugs dangereux refusés', ! \App\Domain\Portal\PortalSlug::isValid('../etc/passwd') && ! \App\Domain\Portal\PortalSlug::isValid('ABCDEFGH1234'));
check('lien externe : https seulement, sans identifiants', \App\Domain\Portal\ExternalUrl::isValid('https://www.starlink.com/account') && ! \App\Domain\Portal\ExternalUrl::isValid('http://a.com') && ! \App\Domain\Portal\ExternalUrl::isValid('https://u:p@a.com') && ! \App\Domain\Portal\ExternalUrl::isValid('javascript:alert(1)'));
check('commande : seuls PENDING_PAYMENT et EXPIRED peuvent livrer', \App\Domain\Portal\PortalOrderStatus::canFulfill('PENDING_PAYMENT') && \App\Domain\Portal\PortalOrderStatus::canFulfill('EXPIRED') && ! \App\Domain\Portal\PortalOrderStatus::canFulfill('FAILED') && ! \App\Domain\Portal\PortalOrderStatus::canFulfill('FULFILLED'));
$pl = \App\Domain\Portal\PortalLabels::all();
$same = true;
foreach (['en', 'ar', 'ha'] as $lc) { $same = $same && array_keys($pl[$lc]) === array_keys($pl['fr']); }
check('portail : mêmes clés de texte en fr/en/ar/ha', $same && count($pl) === 4);
$js = (string) file_get_contents(__DIR__.'/../backend-overlay/resources/portal/portal.js');
$page = \App\Domain\Portal\PortalPageBuilder::build('abcdefgh2345', new \App\Domain\Documents\Brand('<script>x</script>', '+227', '"><img src=x onerror=1>', "a\n</script>", '#102030'), 'ar', 'https://x.test/api/v1', true, $js);
check('page portail : texte propriétaire jamais en HTML, RTL en arabe, 2 scripts', ! str_contains($page, '<script>x') && ! str_contains($page, '<img src=x') && substr_count($page, '<script>') === 2 && str_contains($page, 'dir="rtl"'));
check('portal.js : pas d\'innerHTML ni de </script', ! str_contains($js, 'innerHTML') && ! str_contains($js, '</script'));

// --- Phase 8 : observabilité (logique pure) ---
$prom = \App\Domain\Observability\PrometheusText::render([['name' => 'wifizone_x', 'help' => 'h', 'samples' => [['labels' => ['status' => "a\"b\nc"], 'value' => 3], ['value' => 1.5]]], ['name' => 'bad name', 'help' => 'x', 'samples' => [['value' => 1]]]]);
check('prometheus : étiquettes échappées, nom invalide ignoré', str_contains($prom, 'wifizone_x{status="a\\"b\\nc"} 3') && str_contains($prom, "wifizone_x 1.5\n") && ! str_contains($prom, 'bad name'));
$th = ['needs_attention_max' => 0, 'stuck_payments_max' => 0, 'failed_jobs_max' => 0, 'sync_failed_max' => 0, 'queue_size_max' => 500, 'commands_pending_old_max' => 0, 'offline_ratio_max' => 0.5];
check('alertes : rien à signaler -> silence', \App\Domain\Observability\AlertRules::evaluate(['routers_online' => 3, 'routers_offline' => 1, 'queue_size' => 499], $th) === []);
$al = array_column(\App\Domain\Observability\AlertRules::evaluate(['portal_orders_needs_attention' => 2, 'payments_stuck' => 1, 'queue_size' => 600, 'routers_online' => 1, 'routers_offline' => 3], $th), 'severity', 'rule');
check('alertes : argent = critique, file/routeurs = avertissement', ($al['portal_orders_need_attention'] ?? '') === 'critical' && ($al['payments_stuck'] ?? '') === 'critical' && ($al['queue_backlog'] ?? '') === 'warning' && ($al['routers_offline'] ?? '') === 'warning');

echo $failures === 0 ? "OK : $count vérifications passées.\n" : "$failures échec(s) sur $count.\n";
exit($failures === 0 ? 0 : 1);
