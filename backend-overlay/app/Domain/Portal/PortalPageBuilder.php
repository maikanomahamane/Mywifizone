<?php

namespace App\Domain\Portal;

use App\Domain\Documents\Brand;
use App\Domain\Documents\Html;

/**
 * Page HTML du portail captif (responsive, une seule page, sans bibliothèque externe, sans secret serveur).
 * Les données du propriétaire ne sont JAMAIS écrites dans le HTML : elles passent par un bloc JSON (échappé
 * pour balises, &, ' et ") puis le script les insère avec textContent. Seule la couleur (hex validé) entre dans le CSS.
 */
final class PortalPageBuilder
{
    /** @param string $js contenu de resources/portal/portal.js */
    public static function build(string $slug, Brand $brand, string $locale, string $apiBase, bool $hasLogo, string $js): string
    {
        $locale = PortalLabels::normalize($locale);
        $color = $brand->color;
        $text = $brand->textColor();

        $config = [
            'slug' => $slug,
            'api' => $apiBase,
            'locale' => $locale,
            'labels' => PortalLabels::all(),
        ];
        $json = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);

        $logo = $hasLogo ? '<img class="logo" alt="" src="'.Html::e($apiBase.'/portal/'.$slug.'/logo').'">' : '';
        $name = Html::e($brand->name);
        $slogan = $brand->slogan !== null ? '<p class="slogan">'.Html::e($brand->slogan).'</p>' : '';
        $phone = $brand->phone !== null ? '<p class="contact">'.Html::e($brand->phone).'</p>' : '';
        $terms = $brand->terms !== null ? '<p class="terms">'.Html::multiline($brand->terms).'</p>' : '';

        return '<!doctype html><html lang="'.$locale.'" dir="'.($locale === 'ar' ? 'rtl' : 'ltr').'"><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width,initial-scale=1">'
            .'<meta name="referrer" content="no-referrer"><title>'.$name.'</title><style>'
            .':root{--c:'.$color.';--t:'.$text.'}*{box-sizing:border-box}body{margin:0;font:16px/1.4 system-ui,sans-serif;background:#f4f5f7;color:#1c1c1e}'
            .'header{background:var(--c);color:var(--t);padding:20px 16px;text-align:center}header h1{margin:6px 0 0;font-size:1.3rem}.logo{max-height:64px;max-width:60%}.slogan{margin:4px 0 0;opacity:.9}'
            .'main{max-width:440px;margin:0 auto;padding:16px}.card{background:#fff;border-radius:14px;padding:16px;margin-bottom:14px;box-shadow:0 1px 3px rgba(0,0,0,.08)}'
            .'input,select{width:100%;padding:14px;font-size:1.1rem;border:1px solid #c9ccd1;border-radius:10px;margin:8px 0}'
            .'button{width:100%;padding:14px;font-size:1rem;font-weight:700;border:0;border-radius:10px;background:var(--c);color:var(--t);cursor:pointer;margin-top:6px}'
            .'button.secondary{background:#fff;color:var(--c);border:2px solid var(--c)}button:disabled{opacity:.45}'
            .'.offer{display:flex;flex-direction:column;align-items:flex-start;gap:2px;text-align:start;background:#fff;color:#1c1c1e;border:2px solid var(--c);margin:8px 0}.offer .price{color:var(--c);font-size:1.2rem;font-weight:800}.offer small{opacity:.75;font-weight:400}'
            .'.msg{min-height:1.3em;color:#b3261e}.code{font:700 2rem ui-monospace,monospace;letter-spacing:.15em;text-align:center;margin:10px 0;user-select:all}'
            .'.terms,.contact{font-size:.8rem;opacity:.7;text-align:center}[hidden]{display:none!important}'
            .'</style></head><body><header>'.$logo.'<h1>'.$name.'</h1>'.$slogan.'</header><main>'
            .'<div class="card"><select id="lang" aria-label="Language"></select></div>'
            .'<section id="view-login" class="card"><h2 id="t-enter"></h2>'
            .'<form id="form-login" autocomplete="off"><input id="code-input" inputmode="text" autocapitalize="characters" maxlength="32" required>'
            .'<button id="btn-connect" type="submit"></button></form><p id="login-msg" class="msg"></p>'
            .'<hr><p id="t-noticket"></p><button id="btn-buy" class="secondary" type="button"></button></section>'
            .'<section id="view-offers" class="card" hidden><h2 id="t-offers"></h2><label id="lbl-phone" for="phone"></label>'
            .'<input id="phone" type="tel" inputmode="tel" maxlength="20"><div id="offers"></div><p id="offers-msg" class="msg"></p>'
            .'<button id="btn-back" class="secondary" type="button"></button></section>'
            .'<section id="view-status" class="card" hidden><p id="status-msg"></p><div id="code-box" hidden><div id="code" class="code"></div>'
            .'<button id="btn-use" type="button">OK</button></div></section>'
            .$phone.$terms.'</main><script>var CONFIG='.$json.';</script><script>'.$js.'</script></body></html>';
    }
}
