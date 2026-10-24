<?php

namespace App\Domain\Portal;

/** Textes du portail captif. Traductions ar/ha à faire relire par des locuteurs. */
final class PortalLabels
{
    public const LOCALES = ['fr', 'en', 'ar', 'ha'];

    private const T = [
        'fr' => ['enter_code' => 'Entrez votre code WiFi', 'connect' => 'SE CONNECTER', 'no_ticket' => "Vous n'avez pas de ticket ?", 'buy' => 'ACHETER INTERNET', 'offers' => 'Choisissez votre pass', 'phone' => 'Téléphone (facultatif)', 'pay' => 'Payer', 'back' => 'Retour', 'pending' => 'Paiement en attente… ne fermez pas cette page.', 'success' => 'Paiement confirmé ! Votre code :', 'use_code' => 'Connectez-vous avec ce code.', 'sold_out' => 'Plus de tickets disponibles pour ce pass.', 'failed' => 'Le paiement a échoué ou expiré.', 'attention' => 'Paiement reçu, mais livraison impossible. Contactez le point de vente avec la référence :', 'error' => 'Une erreur est survenue. Réessayez.', 'unavailable' => 'Ce portail est indisponible.', 'bad_link' => "Lien de connexion non reconnu : saisissez le code depuis la page de connexion du WiFi.", 'duration' => 'Durée', 'unlimited' => 'Illimitée', 'min' => 'min', 'hour' => 'h', 'day' => 'j', 'devices' => 'appareils', 'loading' => 'Chargement…', 'language' => 'Langue'],
        'en' => ['enter_code' => 'Enter your WiFi code', 'connect' => 'CONNECT', 'no_ticket' => "Don't have a ticket?", 'buy' => 'BUY INTERNET', 'offers' => 'Choose your pass', 'phone' => 'Phone (optional)', 'pay' => 'Pay', 'back' => 'Back', 'pending' => 'Waiting for payment… keep this page open.', 'success' => 'Payment confirmed! Your code:', 'use_code' => 'Sign in with this code.', 'sold_out' => 'No tickets left for this pass.', 'failed' => 'The payment failed or expired.', 'attention' => 'Payment received, but delivery failed. Contact the shop with the reference:', 'error' => 'Something went wrong. Try again.', 'unavailable' => 'This portal is unavailable.', 'bad_link' => 'Login link not recognised: enter the code from the WiFi login page.', 'duration' => 'Duration', 'unlimited' => 'Unlimited', 'min' => 'min', 'hour' => 'h', 'day' => 'd', 'devices' => 'devices', 'loading' => 'Loading…', 'language' => 'Language'],
        'ar' => ['enter_code' => 'أدخل رمز الواي فاي', 'connect' => 'اتصال', 'no_ticket' => 'ليس لديك تذكرة؟', 'buy' => 'اشترِ الإنترنت', 'offers' => 'اختر باقتك', 'phone' => 'الهاتف (اختياري)', 'pay' => 'ادفع', 'back' => 'رجوع', 'pending' => 'بانتظار الدفع… أبقِ هذه الصفحة مفتوحة.', 'success' => 'تم تأكيد الدفع! رمزك:', 'use_code' => 'سجّل الدخول بهذا الرمز.', 'sold_out' => 'لا توجد تذاكر متاحة لهذه الباقة.', 'failed' => 'فشل الدفع أو انتهت مدته.', 'attention' => 'تم استلام الدفع لكن تعذر التسليم. تواصل مع نقطة البيع بالمرجع:', 'error' => 'حدث خطأ. حاول مرة أخرى.', 'unavailable' => 'هذه البوابة غير متاحة.', 'bad_link' => 'رابط الدخول غير معروف: أدخل الرمز من صفحة دخول الواي فاي.', 'duration' => 'المدة', 'unlimited' => 'غير محدودة', 'min' => 'د', 'hour' => 'س', 'day' => 'ي', 'devices' => 'أجهزة', 'loading' => 'جارٍ التحميل…', 'language' => 'اللغة'],
        'ha' => ['enter_code' => 'Shigar da lambar WiFi ɗinka', 'connect' => 'HAƊA', 'no_ticket' => 'Ba ka da tikiti?', 'buy' => 'SAYI INTANET', 'offers' => 'Zaɓi fakitinka', 'phone' => 'Waya (ba dole ba)', 'pay' => 'Biya', 'back' => 'Koma', 'pending' => 'Ana jiran biya… kada ka rufe wannan shafi.', 'success' => 'An tabbatar da biya! Lambarka:', 'use_code' => 'Shiga da wannan lambar.', 'sold_out' => 'Babu sauran tikiti na wannan fakiti.', 'failed' => 'Biyan ya gaza ko ya ƙare.', 'attention' => 'An karɓi kuɗi, amma ba a iya kai tikitin ba. Tuntuɓi wurin sayarwa da wannan lamba:', 'error' => 'An samu matsala. Sake gwadawa.', 'unavailable' => 'Wannan shafi baya aiki.', 'bad_link' => 'Ba a gane mahaɗin shiga ba: shigar da lambar daga shafin shiga na WiFi.', 'duration' => 'Tsawon lokaci', 'unlimited' => 'Mara iyaka', 'min' => 'min', 'hour' => 'awa', 'day' => 'rana', 'devices' => 'na\'ura', 'loading' => 'Ana lodawa…', 'language' => 'Harshe'],
    ];

    /** @return array<string, array<string, string>> */
    public static function all(): array
    {
        return self::T;
    }

    public static function normalize(?string $locale): string
    {
        $l = strtolower(substr((string) $locale, 0, 2));

        return in_array($l, self::LOCALES, true) ? $l : 'fr';
    }
}
