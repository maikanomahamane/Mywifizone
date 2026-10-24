<?php

namespace App\Domain\Documents;

/**
 * HTML des feuilles de tickets (A4, A5, individuel, thermique) pour le moteur PDF. Fonction pure, testée sans moteur.
 * Toute donnée utilisateur passe par Html::e() ; la couleur ne peut être qu'un #RRGGBB validé ; le seul <img> émis
 * est le logo (data: URI base64 produit par LogoProcessor) : aucun contenu distant n'est référencé.
 */
final class TicketHtmlBuilder
{
    /**
     * @param  list<TicketData>  $tickets
     * @param  array{page_w: int, page_h: int, margin: int, cols: int, rows: int, card_w: float, card_h: int, per_page: int, qr_size: float}  $layout
     */
    public static function build(array $tickets, array $layout, string $locale, Brand $brand, bool $withQr): string
    {
        $locale = DocumentLabels::normalize($locale);
        $rtl = DocumentLabels::isRtl($locale);
        $compact = $layout['per_page'] > 1;

        $html = '<html><head><meta charset="utf-8"><style>'.self::css($brand, $rtl, $compact).'</style></head>'
            .'<body dir="'.($rtl ? 'rtl' : 'ltr').'" lang="'.$locale.'">';

        foreach (array_chunk($tickets, $layout['per_page']) as $i => $pageTickets) {
            if ($i > 0) {
                $html .= '<pagebreak />';
            }
            $html .= self::grid($pageTickets, $layout, $locale, $brand, $withQr, $rtl, $compact);
        }

        return $html.'</body></html>';
    }

    /** @param list<TicketData> $tickets */
    private static function grid(array $tickets, array $layout, string $locale, Brand $brand, bool $withQr, bool $rtl, bool $compact): string
    {
        $out = '<table class="grid" dir="'.($rtl ? 'rtl' : 'ltr').'">';
        foreach (array_chunk($tickets, $layout['cols']) as $row) {
            $out .= '<tr>';
            for ($c = 0; $c < $layout['cols']; $c++) {
                $out .= isset($row[$c])
                    ? self::cell($row[$c], $layout, $locale, $brand, $withQr, $rtl, $compact)
                    : '<td class="empty" style="width:'.self::mm($layout['card_w']).'mm;height:'.$layout['card_h'].'mm"></td>';
            }
            $out .= '</tr>';
        }

        return $out.'</table>';
    }

    private static function cell(TicketData $t, array $layout, string $locale, Brand $brand, bool $withQr, bool $rtl, bool $compact): string
    {
        $l = fn (string $key): string => Html::e(DocumentLabels::get($locale, $key));

        $logo = $brand->logoDataUri();
        $head = ($logo !== null ? '<img src="'.$logo.'" style="height:'.($compact ? 6 : 9).'mm" /> ' : '')
            .'<b>'.Html::e($brand->name).'</b>'
            .($brand->phone ? ' &nbsp; '.Html::e($brand->phone) : '');

        $lines = [];
        $lines[] = '<b>'.Html::e($t->planName).'</b>'.($t->isVip ? ' · '.$l('vip') : '');
        $lines[] = $l('price').' : '.Html::e($t->priceText);
        $lines[] = $l('validity').' : '.Html::e(DurationFormatter::format($t->durationMinutes, $locale));
        if ($t->validUntilText !== null) {
            $lines[] = $l('expires').' : '.Html::e($t->validUntilText);
        }
        if ($t->deviceLimit !== null) {
            $lines[] = $l('devices').' : '.(int) $t->deviceLimit;
        }
        if ($t->rateLimit !== null && ! $compact) {
            $lines[] = $l('speed').' : '.Html::e($t->rateLimit);
        }

        $qr = $withQr
            ? '<barcode code="'.Html::e($t->qrPayload).'" type="QR" error="M" size="'.$layout['qr_size'].'" disableborder="1" />'
            : '';

        $footer = $brand->slogan ? '<i>'.Html::e($brand->slogan).'</i><br/>' : '';
        $footer .= $l('connect_hint');
        if ($brand->terms) {
            $footer .= '<br/>'.$l('terms').' : '.Html::multiline(self::cut($brand->terms, $compact ? 90 : 250));
        }

        return '<td class="cell" style="width:'.self::mm($layout['card_w']).'mm;height:'.$layout['card_h'].'mm">'
            .'<table class="inner" width="100%"><tr><td class="head" colspan="2">'.$head.'</td></tr>'
            .'<tr><td class="body">'.implode('<br/>', $lines)
            .'<div class="codelabel">'.$l('ticket_code').'</div><div class="code">'.Html::e($t->code).'</div></td>'
            .'<td class="qr">'.$qr.'</td></tr>'
            .'<tr><td class="foot" colspan="2">'.$footer.'</td></tr></table></td>';
    }

    private static function css(Brand $brand, bool $rtl, bool $compact): string
    {
        $color = $brand->color;          // #RRGGBB validé par Brand/HexColor
        $text = $brand->textColor();
        $align = $rtl ? 'right' : 'left';
        $font = $compact ? '8pt' : '10pt';
        $code = $compact ? '15pt' : '20pt';

        return "body{font-family:dejavusans;font-size:$font;text-align:$align}"
            .'table.grid{border-collapse:separate;border-spacing:0;width:100%}'
            ."td.cell{border:0.3mm dashed #888;padding:1mm;vertical-align:top}"
            .'td.empty{border:0}'
            .'table.inner{border-collapse:collapse}'
            ."td.head{background-color:$color;color:$text;padding:1mm;font-size:$font}"
            .'td.body{vertical-align:top;padding:1mm}'
            .'td.qr{vertical-align:middle;text-align:center;width:24mm}'
            .'td.foot{font-size:6pt;color:#444;padding:0.5mm}'
            .'div.codelabel{font-size:6pt;color:#666;margin-top:1mm}'
            ."div.code{font-family:dejavusansmono;font-size:$code;font-weight:bold;letter-spacing:0.3mm;direction:ltr;text-align:$align}";
    }

    private static function cut(string $text, int $max): string
    {
        return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1).'…' : $text;
    }

    private static function mm(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    }
}
