<?php

namespace App\Infrastructure\Documents;

interface PdfEngine
{
    /**
     * Convertit un HTML (déjà échappé par Html::e) en PDF.
     *
     * @param  array{page_w: int, page_h: int, margin: int}  $page  dimensions en millimètres
     * @return string octets du PDF
     */
    public function render(string $html, array $page, string $locale, string $title): string;
}
