<?php

namespace App\Domain\Documents;

/**
 * Libellés des documents imprimés (tickets, reçus, factures) en français, anglais, arabe et haoussa.
 * Texte d'interface FIXE : jamais de données utilisateur ici. Une clé absente d'une langue retombe sur le français.
 * Les traductions arabe et haoussa sont à faire relire par un locuteur avant diffusion.
 */
final class DocumentLabels
{
    public const LOCALES = ['fr', 'en', 'ar', 'ha'];

    private const RTL = ['ar'];

    private const TEXT = [
        'fr' => [
            'ticket_code' => 'Code', 'plan' => 'Formule', 'price' => 'Prix', 'validity' => 'Durée', 'expires' => 'Valable jusqu\'au',
            'devices' => 'Appareils', 'speed' => 'Débit', 'unlimited' => 'Illimitée', 'vip' => 'VIP', 'free' => 'Gratuit',
            'connect_hint' => 'Connectez-vous au WiFi, puis saisissez ce code comme identifiant et mot de passe.',
            'scan' => 'Scannez pour vous connecter', 'terms' => 'Conditions',
            'receipt' => 'Reçu', 'receipt_no' => 'N°', 'date' => 'Date', 'seller' => 'Vendeur', 'payment' => 'Paiement',
            'reference' => 'Référence', 'amount' => 'Montant', 'ticket' => 'Ticket', 'voided' => 'ANNULÉ', 'thanks' => 'Merci !',
            'invoice' => 'Facture', 'period' => 'Période', 'subtotal' => 'Sous-total', 'discount' => 'Remise', 'total' => 'Total',
            'status' => 'Statut', 'paid' => 'Payée', 'open' => 'À payer', 'void' => 'Annulée', 'billed_to' => 'Facturé à',
            'cycle_MONTHLY' => 'Mensuel', 'cycle_YEARLY' => 'Annuel',
            'pay_CASH' => 'Espèces', 'pay_MOBILE_MONEY' => 'Mobile money', 'pay_BANK' => 'Banque', 'pay_OTHER' => 'Autre',
            'min' => 'min', 'hour' => 'h', 'day' => 'j',
        ],
        'en' => [
            'ticket_code' => 'Code', 'plan' => 'Plan', 'price' => 'Price', 'validity' => 'Duration', 'expires' => 'Valid until',
            'devices' => 'Devices', 'speed' => 'Speed', 'unlimited' => 'Unlimited', 'vip' => 'VIP', 'free' => 'Free',
            'connect_hint' => 'Connect to the WiFi, then enter this code as username and password.',
            'scan' => 'Scan to connect', 'terms' => 'Terms',
            'receipt' => 'Receipt', 'receipt_no' => 'No.', 'date' => 'Date', 'seller' => 'Seller', 'payment' => 'Payment',
            'reference' => 'Reference', 'amount' => 'Amount', 'ticket' => 'Ticket', 'voided' => 'VOIDED', 'thanks' => 'Thank you!',
            'invoice' => 'Invoice', 'period' => 'Period', 'subtotal' => 'Subtotal', 'discount' => 'Discount', 'total' => 'Total',
            'status' => 'Status', 'paid' => 'Paid', 'open' => 'Due', 'void' => 'Void', 'billed_to' => 'Billed to',
            'cycle_MONTHLY' => 'Monthly', 'cycle_YEARLY' => 'Yearly',
            'pay_CASH' => 'Cash', 'pay_MOBILE_MONEY' => 'Mobile money', 'pay_BANK' => 'Bank', 'pay_OTHER' => 'Other',
            'min' => 'min', 'hour' => 'h', 'day' => 'd',
        ],
        'ar' => [
            'ticket_code' => 'الرمز', 'plan' => 'الباقة', 'price' => 'السعر', 'validity' => 'المدة', 'expires' => 'صالح حتى',
            'devices' => 'الأجهزة', 'speed' => 'السرعة', 'unlimited' => 'غير محدودة', 'vip' => 'VIP', 'free' => 'مجاني',
            'connect_hint' => 'اتصل بشبكة الواي فاي ثم أدخل هذا الرمز كاسم مستخدم وكلمة مرور.',
            'scan' => 'امسح للاتصال', 'terms' => 'الشروط',
            'receipt' => 'إيصال', 'receipt_no' => 'رقم', 'date' => 'التاريخ', 'seller' => 'البائع', 'payment' => 'الدفع',
            'reference' => 'المرجع', 'amount' => 'المبلغ', 'ticket' => 'التذكرة', 'voided' => 'ملغى', 'thanks' => 'شكراً لكم!',
            'invoice' => 'فاتورة', 'period' => 'الفترة', 'subtotal' => 'المجموع الفرعي', 'discount' => 'الخصم', 'total' => 'المجموع',
            'status' => 'الحالة', 'paid' => 'مدفوعة', 'open' => 'مستحقة', 'void' => 'ملغاة', 'billed_to' => 'الفاتورة إلى',
            'cycle_MONTHLY' => 'شهري', 'cycle_YEARLY' => 'سنوي',
            'pay_CASH' => 'نقداً', 'pay_MOBILE_MONEY' => 'محفظة إلكترونية', 'pay_BANK' => 'بنك', 'pay_OTHER' => 'أخرى',
            'min' => 'د', 'hour' => 'ساعة', 'day' => 'يوم',
        ],
        'ha' => [
            'ticket_code' => 'Lamba', 'plan' => 'Fakiti', 'price' => 'Farashi', 'validity' => 'Tsawon lokaci', 'expires' => 'Yana aiki har',
            'devices' => 'Na\'urori', 'speed' => 'Gudu', 'unlimited' => 'Ba iyaka', 'vip' => 'VIP', 'free' => 'Kyauta',
            'connect_hint' => 'Haɗa da WiFi, sannan ka shigar da wannan lambar a matsayin sunan mai amfani da kalmar sirri.',
            'scan' => 'Duba don haɗawa', 'terms' => 'Sharuɗɗa',
            'receipt' => 'Rasit', 'receipt_no' => 'Lamba', 'date' => 'Kwanan wata', 'seller' => 'Mai sayarwa', 'payment' => 'Biyan kuɗi',
            'reference' => 'Lambar ciniki', 'amount' => 'Adadi', 'ticket' => 'Tikiti', 'voided' => 'AN SOKE', 'thanks' => 'Na gode!',
            'invoice' => 'Takardar kuɗi', 'period' => 'Lokaci', 'subtotal' => 'Jimlar farko', 'discount' => 'Ragi', 'total' => 'Jimla',
            'status' => 'Matsayi', 'paid' => 'An biya', 'open' => 'Ana bin bashi', 'void' => 'An soke', 'billed_to' => 'An aika zuwa',
            'cycle_MONTHLY' => 'Na wata-wata', 'cycle_YEARLY' => 'Na shekara-shekara',
            'pay_CASH' => 'Kuɗi a hannu', 'pay_MOBILE_MONEY' => 'Mobile money', 'pay_BANK' => 'Banki', 'pay_OTHER' => 'Wani',
            'min' => 'min', 'hour' => 'awa', 'day' => 'kwana',
        ],
    ];

    public static function normalize(?string $locale): string
    {
        $l = strtolower(substr((string) $locale, 0, 2));

        return in_array($l, self::LOCALES, true) ? $l : 'fr';
    }

    public static function get(string $locale, string $key): string
    {
        $locale = self::normalize($locale);

        return self::TEXT[$locale][$key] ?? self::TEXT['fr'][$key] ?? $key;
    }

    public static function isRtl(string $locale): bool
    {
        return in_array(self::normalize($locale), self::RTL, true);
    }

    /** Vrai si la clé est définie DANS cette langue (sans repli sur le français). */
    public static function has(string $locale, string $key): bool
    {
        return isset(self::TEXT[self::normalize($locale)][$key]) && self::TEXT[self::normalize($locale)][$key] !== '';
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::TEXT['fr']);
    }
}
