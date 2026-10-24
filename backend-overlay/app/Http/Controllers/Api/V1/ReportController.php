<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Business\PaymentMethod;
use App\Domain\Business\ReportPeriod;
use App\Http\Controllers\Controller;
use App\Models\HotspotProfile;
use App\Models\Site;
use App\Services\PlanEntitlements;
use App\Services\ReportService;
use App\Support\ApiResponse;
use App\Support\Rules;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ReportController extends Controller
{
    public function __construct(private readonly ReportService $reports, private readonly PlanEntitlements $entitlements) {}

    /** Rapport de ventes : période (daily/weekly/monthly/annual), plage de dates (jours locaux du tenant), filtres, regroupement. */
    public function sales(Request $request): JsonResponse
    {
        $request->merge(['currency' => $request->filled('currency') ? strtoupper((string) $request->input('currency')) : null]);

        $f = $request->validate([
            'period' => ['required', Rule::in(ReportPeriod::ALL)],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'site_id' => ['nullable', 'uuid', Rules::ownedBy(Site::class)],
            'agent_id' => ['nullable', 'uuid'],
            'profile_id' => ['nullable', 'uuid', Rules::ownedBy(HotspotProfile::class)],
            'currency' => ['nullable', 'regex:/^[A-Z]{3}$/'],
            'payment_method' => ['nullable', Rule::in(PaymentMethod::ALL)],
            'group_by' => ['nullable', Rule::in(ReportService::GROUPS)],
        ]);

        // Journalier : statistiques de base (toutes formules). Hebdo/mensuel/annuel : fonction « reports ».
        $this->entitlements->assertFeature($f['period'] === ReportPeriod::DAILY ? 'basic_stats' : 'reports');

        $days = CarbonImmutable::parse($f['from'])->diffInDays(CarbonImmutable::parse($f['to'])) + 1;
        if ($days > ReportPeriod::maxDays($f['period'])) {
            throw ValidationException::withMessages(['to' => 'Plage trop étendue pour cette période (maximum '.ReportPeriod::maxDays($f['period']).' jours).']);
        }

        $result = $this->reports->sales($f['period'], $f['from'], $f['to'], $f, $f['group_by'] ?? 'none');

        return ApiResponse::ok($result, ['period' => $f['period'], 'from' => $f['from'], 'to' => $f['to'], 'group_by' => $f['group_by'] ?? 'none']);
    }
}
