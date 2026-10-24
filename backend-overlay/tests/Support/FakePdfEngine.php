<?php

namespace Tests\Support;

use App\Infrastructure\Documents\PdfEngine;

/** Moteur factice : mémorise le HTML reçu et renvoie un faux PDF. Permet de tester tout sauf le rendu lui-même. */
final class FakePdfEngine implements PdfEngine
{
    public int $calls = 0;

    public string $html = '';

    /** @var array<string, int> */
    public array $page = [];

    public string $locale = '';

    public function render(string $html, array $page, string $locale, string $title): string
    {
        $this->calls++;
        $this->html = $html;
        $this->page = $page;
        $this->locale = $locale;

        return '%PDF-FAKE';
    }
}
