<?php

namespace App\Services;

use App\Domain\Documents\InvoiceHtmlBuilder;
use App\Domain\Documents\MoneyFormatter;
use App\Domain\Documents\ReceiptHtmlBuilder;
use App\Domain\Documents\TicketData;
use App\Domain\Documents\TicketHtmlBuilder;
use App\Domain\Documents\TicketLayout;
use App\Domain\Documents\TicketQr;
use App\Infrastructure\Documents\PdfEngine;
use App\Models\Currency;
use App\Models\HotspotProfile;
use App\Models\Invoice;
use App\Models\Sale;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Voucher;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/** Assemble les données (sous scope tenant) puis délègue le rendu : constructeurs HTML purs + PdfEngine. */
final class DocumentService
{
    /** @var array<string, ?Currency> */
    private array $currencies = [];

    public function __construct(
        private readonly PdfEngine $engine,
        private readonly BrandingService $branding,
    ) {}

    /** @param Collection<int, Voucher> $vouchers */
    public function ticketsPdf(Tenant $tenant, Collection $vouchers, string $format, int $thermalWidth, string $locale, bool $withQr): string
    {
        $brand = $this->branding->brand($tenant);
        $tz = SaleService::timezoneOf($tenant->getKey());
        $profiles = HotspotProfile::whereIn('id', $vouchers->pluck('profile_id')->unique())->get()->keyBy('id');
        $layout = TicketLayout::for($format, $thermalWidth);

        $tickets = $vouchers->map(function (Voucher $v) use ($profiles, $brand, $tz, $locale): TicketData {
            return new TicketData(
                code: $v->code,
                planName: (string) ($profiles[$v->profile_id]->name ?? ''),
                priceText: $v->price > 0 ? $this->money($v->price, $v->currency) : \App\Domain\Documents\DocumentLabels::get($locale, 'free'),
                durationMinutes: $v->duration_minutes,
                validUntilText: $v->valid_until?->setTimezone($tz)->format('d/m/Y'),
                deviceLimit: $v->device_limit,
                rateLimit: $v->rate_limit,
                isVip: (bool) $v->is_vip,
                qrPayload: TicketQr::payload($v->code, $brand->portalUrl),
            );
        })->values()->all();

        $html = TicketHtmlBuilder::build($tickets, $layout, $locale, $brand, $withQr);

        return $this->engine->render($html, $layout, $locale, $brand->name);
    }

    public function receiptPdf(Tenant $tenant, Sale $sale, string $format, int $thermalWidth, string $locale): string
    {
        $brand = $this->branding->brand($tenant);
        $tz = SaleService::timezoneOf($tenant->getKey());
        $profile = $sale->profile_id ? HotspotProfile::find($sale->profile_id) : null;
        $voucher = $sale->voucher_id ? Voucher::find($sale->voucher_id) : null;
        $seller = $sale->agent_id ? User::query()->where('tenant_id', $tenant->getKey())->whereKey($sale->agent_id)->value('name') : null;

        $html = ReceiptHtmlBuilder::build([
            'number' => strtoupper(substr((string) $sale->getKey(), 0, 8)),
            'date_text' => $sale->sold_at->setTimezone($tz)->format('d/m/Y H:i'),
            'seller' => $seller,
            'plan' => $profile?->name,
            'duration_minutes' => $voucher?->duration_minutes ?? $profile?->duration_minutes,
            'amount_text' => $this->money($sale->amount, $sale->currency),
            'payment_method' => $sale->payment_method,
            'reference' => $sale->provider_transaction_id,
            'code' => $voucher?->code,
            'voided' => $sale->status === Sale::VOIDED,
        ], $locale, $brand, $format === 'thermal');

        return $this->engine->render($html, ReceiptHtmlBuilder::page($format, $thermalWidth), $locale, $brand->name);
    }

    public function invoicePdf(Tenant $tenant, Invoice $invoice, string $locale): string
    {
        $tz = SaleService::timezoneOf($tenant->getKey());
        $fmt = fn (?CarbonInterface $d, string $f = 'd/m/Y'): ?string => $d?->setTimezone($tz)->format($f);

        $html = InvoiceHtmlBuilder::build([
            'number' => (string) $invoice->number,
            'status' => $invoice->status,
            'plan' => (string) ($invoice->plan?->name ?? $invoice->plan?->code ?? ''),
            'cycle' => (string) $invoice->billing_cycle,
            'period_text' => trim(($fmt($invoice->period_start) ?? '').' – '.($fmt($invoice->period_end) ?? ''), ' –'),
            'date_text' => (string) $fmt($invoice->created_at),
            'billed_to' => $tenant->name,
            'subtotal_text' => $this->money($invoice->subtotal, $invoice->currency),
            'discount_text' => $invoice->discount > 0 ? $this->money($invoice->discount, $invoice->currency) : null,
            'total_text' => $this->money($invoice->total, $invoice->currency),
            'paid_text' => $fmt($invoice->paid_at),
        ], $locale);

        return $this->engine->render($html, ['page_w' => 210, 'page_h' => 297, 'margin' => 15], $locale, 'MY WIFIZONE '.$invoice->number);
    }

    /** Montant en unités mineures -> texte, selon la table des devises (repli : 0 décimale, code de la devise). */
    public function money(int $amount, string $currency): string
    {
        $this->currencies[$currency] ??= Currency::find($currency);
        $c = $this->currencies[$currency];

        return MoneyFormatter::format($amount, (int) ($c->minor_unit ?? 0), (string) ($c->symbol ?: $currency));
    }
}
