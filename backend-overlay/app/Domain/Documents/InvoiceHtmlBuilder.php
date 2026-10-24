<?php

namespace App\Domain\Documents;

/**
 * HTML d'une facture d'abonnement MY WIFIZONE (A4). Document commercial simple : il ne porte PAS de mentions légales
 * fiscales (NIF, TVA...) tant que l'émetteur ne les a pas fournies ; numérotation non séquentielle (voir ADR 0003).
 */
final class InvoiceHtmlBuilder
{
    /**
     * @param  array{number: string, status: string, plan: string, cycle: string, period_text: string, date_text: string, billed_to: string, subtotal_text: string, discount_text: ?string, total_text: string, paid_text: ?string}  $i
     */
    public static function build(array $i, string $locale): string
    {
        $locale = DocumentLabels::normalize($locale);
        $rtl = DocumentLabels::isRtl($locale);
        $l = fn (string $key): string => Html::e(DocumentLabels::get($locale, $key));
        $align = $rtl ? 'right' : 'left';
        $status = match ($i['status']) {
            'PAID' => $l('paid'),
            'VOID' => $l('void'),
            default => $l('open'),
        };

        $rows = [
            [$l('invoice'), Html::e($i['number'])],
            [$l('date'), Html::e($i['date_text'])],
            [$l('billed_to'), Html::e($i['billed_to'])],
            [$l('plan'), Html::e($i['plan']).' · '.$l('cycle_'.preg_replace('/[^A-Z]/', '', $i['cycle']))],
            [$l('period'), Html::e($i['period_text'])],
            [$l('status'), $status.($i['paid_text'] ? ' ('.Html::e($i['paid_text']).')' : '')],
            [$l('subtotal'), Html::e($i['subtotal_text'])],
        ];
        if ($i['discount_text'] !== null) {
            $rows[] = [$l('discount'), '-'.Html::e($i['discount_text'])];
        }

        $html = '<html><head><meta charset="utf-8"><style>'
            ."body{font-family:dejavusans;font-size:11pt;text-align:$align}"
            .'h1{font-size:20pt;color:'.HexColor::DEFAULT.'}'
            .'table.rows{width:100%;border-collapse:collapse;margin-top:6mm}'
            .'table.rows td{padding:2mm;border-bottom:0.2mm solid #ccc}'
            .'div.total{font-size:16pt;font-weight:bold;margin-top:6mm}'
            .'</style></head><body dir="'.($rtl ? 'rtl' : 'ltr').'" lang="'.$locale.'">'
            .'<h1>MY WIFIZONE</h1><div><b>'.$l('invoice').'</b></div><table class="rows">';
        foreach ($rows as [$k, $v]) {
            $html .= '<tr><td>'.$k.'</td><td>'.$v.'</td></tr>';
        }

        return $html.'</table><div class="total">'.$l('total').' : '.Html::e($i['total_text']).'</div></body></html>';
    }
}
