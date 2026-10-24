<?php

namespace App\Infrastructure\Documents;

use App\Domain\Documents\DocumentLabels;
use App\Support\ApiException;

/**
 * Moteur PDF basé sur mPDF (composer require mpdf/mpdf) : gère le texte arabe (écriture de droite à gauche, formes
 * contextuelles), les caractères hausa et les QR codes (balise <barcode>). Écrit sans pouvoir être exécuté dans
 * l'environnement de rédaction : le rendu réel est vérifié par le test PdfRenderingTest (CI) qui produit des PDF d'exemple.
 */
final class MpdfEngine implements PdfEngine
{
    public function render(string $html, array $page, string $locale, string $title): string
    {
        if (! class_exists(\Mpdf\Mpdf::class)) {
            throw new ApiException('pdf_engine_unavailable', 'Le moteur PDF n\'est pas installé.', 503);
        }

        $temp = (string) config('documents.mpdf_temp_dir');
        if (! is_dir($temp)) {
            @mkdir($temp, 0775, true);
        }

        $m = $page['margin'];
        $mpdf = new \Mpdf\Mpdf([
            'mode' => 'utf-8',
            'format' => [$page['page_w'], $page['page_h']],
            'margin_left' => $m, 'margin_right' => $m, 'margin_top' => $m, 'margin_bottom' => $m,
            'margin_header' => 0, 'margin_footer' => 0,
            'tempDir' => $temp,
            'default_font' => 'dejavusans',
            'autoScriptToLang' => true,
            'autoLangToFont' => true,
            'useSubstitutions' => true,
        ]);
        $mpdf->showImageErrors = false;
        $mpdf->SetTitle(mb_substr($title, 0, 120));
        $mpdf->SetCreator('MY WIFIZONE');
        if (DocumentLabels::isRtl($locale)) {
            $mpdf->SetDirectionality('rtl');
        }

        $mpdf->WriteHTML($html);

        return $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
    }
}
