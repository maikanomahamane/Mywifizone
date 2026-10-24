<?php

namespace App\Domain\Documents;

/** HTML d'un reçu de vente (A4, A5 ou thermique). Le code du ticket est MASQUÉ (3 derniers caractères). */
final class ReceiptHtmlBuilder
{
    public const FORMATS = ['a4', 'a5', 'thermal'];

    /** @return array{page_w: int, page_h: int, margin: int} */
    public static function page(string $format, int $thermalWidth = 58): array
    {
        return match ($format) {
            'a4' => ['page_w' => 210, 'page_h' => 297, 'margin' => 15],
            'a5' => ['page_w' => 148, 'page_h' => 210, 'margin' => 10],
            default => ['page_w' => $thermalWidth === 80 ? 80 : 58, 'page_h' => 150, 'margin' => 3],
        };
    }

    public static function mask(string $code): string
    {
        return str_repeat('•', max(0, strlen($code) - 3)).substr($code, -3);
    }

    /**
     * @param  array{number: string, date_text: string, seller: ?string, plan: ?string, duration_minutes: ?int, amount_text: string, payment_method: string, reference: ?string, code: ?string, voided: bool}  $r
     */
    public static function build(array $r, string $locale, Brand $brand, bool $narrow): string
    {
        $locale = DocumentLabels::normalize($locale);
        $rtl = DocumentLabels::isRtl($locale);
        $l = fn (string $key): string => Html::e(DocumentLabels::get($locale, $key));
        $align = $rtl ? 'right' : 'left';
        $size = $narrow ? '8pt' : '11pt';
        $color = $brand->color;
        $text = $brand->textColor();

        $rows = [
            [$l('receipt_no'), Html::e($r['number'])],
            [$l('date'), Html::e($r['date_text'])],
        ];
        if ($r['seller'] !== null) {
            $rows[] = [$l('seller'), Html::e($r['seller'])];
        }
        if ($r['plan'] !== null) {
            $rows[] = [$l('plan'), Html::e($r['plan']).' ('.Html::e(DurationFormatter::format($r['duration_minutes'], $locale)).')'];
        }
        if ($r['code'] !== null) {
            $rows[] = [$l('ticket'), '<span style="font-family:dejavusansmono">'.Html::e(self::mask($r['code'])).'</span>'];
        }
        $rows[] = [$l('payment'), $l('pay_'.preg_replace('/[^A-Z_]/', '', $r['payment_method']))];
        if ($r['reference'] !== null) {
            $rows[] = [$l('reference'), Html::e($r['reference'])];
        }

        $html = '<html><head><meta charset="utf-8"><style>'
            ."body{font-family:dejavusans;font-size:$size;text-align:$align}"
            ."div.head{background-color:$color;color:$text;padding:2mm;text-align:center}"
            .'table.rows{width:100%;border-collapse:collapse;margin-top:3mm}'
            .'table.rows td{padding:1mm;border-bottom:0.2mm solid #ccc}'
            .'div.total{font-size:'.($narrow ? '12pt' : '16pt').';font-weight:bold;text-align:center;margin-top:4mm}'
            .'div.void{color:#B00020;font-size:14pt;font-weight:bold;text-align:center;margin-top:3mm}'
            .'div.small{font-size:7pt;color:#555;text-align:center;margin-top:3mm}'
            .'</style></head><body dir="'.($rtl ? 'rtl' : 'ltr').'" lang="'.$locale.'">';

        $logo = $brand->logoDataUri();
        $html .= '<div class="head">'.($logo !== null ? '<img src="'.$logo.'" style="height:8mm" /><br/>' : '')
            .'<b>'.Html::e($brand->name).'</b>'.($brand->phone ? '<br/>'.Html::e($brand->phone) : '').'</div>';
        $html .= '<div style="text-align:center;margin-top:2mm"><b>'.$l('receipt').'</b></div>';
        $html .= '<table class="rows">';
        foreach ($rows as [$k, $v]) {
            $html .= '<tr><td>'.$k.'</td><td>'.$v.'</td></tr>';
        }
        $html .= '</table>';
        $html .= '<div class="total">'.$l('amount').' : '.Html::e($r['amount_text']).'</div>';
        if ($r['voided']) {
            $html .= '<div class="void">'.$l('voided').'</div>';
        }
        $html .= '<div class="small">'.($brand->slogan ? Html::e($brand->slogan).'<br/>' : '').$l('thanks').'</div>';

        return $html.'</body></html>';
    }
}
