<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Business\PaymentMethod;
use App\Domain\Documents\CsvWriter;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\HotspotProfile;
use App\Models\Sale;
use App\Models\Site;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\PlanEntitlements;
use App\Support\ApiException;
use App\Support\Rules;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Export CSV des ventes (propriétaire, fonction `reports`). Anti-injection de formules dans CsvWriter. */
class ExportController extends Controller
{
    public function __construct(private readonly PlanEntitlements $entitlements, private readonly AuditLogger $audit) {}

    public function sales(Request $request): StreamedResponse
    {
        $request->merge(['currency' => $request->filled('currency') ? strtoupper((string) $request->input('currency')) : null]);

        $f = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'site_id' => ['nullable', 'uuid', Rules::ownedBy(Site::class)],
            'agent_id' => ['nullable', 'uuid'],
            'profile_id' => ['nullable', 'uuid', Rules::ownedBy(HotspotProfile::class)],
            'currency' => ['nullable', 'regex:/^[A-Z]{3}$/'],
            'payment_method' => ['nullable', Rule::in(PaymentMethod::ALL)],
            'include_voided' => ['nullable', 'boolean'],
        ]);
        $this->entitlements->assertFeature('reports');

        $days = CarbonImmutable::parse($f['from'])->diffInDays(CarbonImmutable::parse($f['to'])) + 1;
        if ($days > (int) config('documents.csv_max_days')) {
            throw ValidationException::withMessages(['to' => 'Plage trop étendue (maximum '.config('documents.csv_max_days').' jours).']);
        }

        $query = Sale::query()
            ->whereBetween('sold_on', [$f['from'], $f['to']])
            ->when(! $request->boolean('include_voided'), fn ($q) => $q->where('status', Sale::COMPLETED))
            ->when($f['site_id'] ?? null, fn ($q, $v) => $q->where('site_id', $v))
            ->when($f['agent_id'] ?? null, fn ($q, $v) => $q->where('agent_id', $v))
            ->when($f['profile_id'] ?? null, fn ($q, $v) => $q->where('profile_id', $v))
            ->when($f['currency'] ?? null, fn ($q, $v) => $q->where('currency', $v))
            ->when($f['payment_method'] ?? null, fn ($q, $v) => $q->where('payment_method', $v));

        $total = (clone $query)->count();
        $max = (int) config('documents.max_csv_rows');
        if ($total > $max) {
            throw new ApiException('export_too_large', 'Trop de lignes : réduisez la période ou ajoutez un filtre.', 422, ['max' => $max, 'rows' => $total]);
        }

        $tenantId = $request->user()->tenant_id;
        $this->audit->log('export.sales_csv', $request->user(), null, ['rows' => $total, 'from' => $f['from'], 'to' => $f['to']]);

        $name = 'ventes-'.$f['from'].'_'.$f['to'].'.csv';

        // Le flux s'exécute après le middleware tenant (contexte déjà nettoyé) : on le rétablit explicitement.
        return response()->streamDownload(fn () => app(TenantContext::class)->run($tenantId, function () use ($query, $tenantId) {
            echo CsvWriter::BOM;
            echo CsvWriter::row(['id', 'date', 'sold_at_utc', 'status', 'amount_minor', 'currency', 'payment_method', 'reference', 'agent', 'plan', 'site_id']);

            $query->orderBy('sold_at')->orderBy('id')->chunk(1000, function ($sales) use ($tenantId) {
                // Noms résolus par paquet (pas de jointure : le scope tenant porte sur la table des ventes).
                $agents = User::where('tenant_id', $tenantId)->whereIn('id', $sales->pluck('agent_id')->filter()->unique())->pluck('name', 'id');
                $plans = HotspotProfile::whereIn('id', $sales->pluck('profile_id')->filter()->unique())->pluck('name', 'id');

                foreach ($sales as $s) {
                    echo CsvWriter::row([
                        $s->getKey(), substr((string) $s->sold_on, 0, 10), $s->sold_at?->utc()->format('Y-m-d H:i:s'), $s->status,
                        (int) $s->amount, $s->currency, $s->payment_method, $s->provider_transaction_id,
                        $agents[$s->agent_id] ?? null, $plans[$s->profile_id] ?? null, $s->site_id,
                    ]);
                }
                flush();
            });
        }), $name, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
