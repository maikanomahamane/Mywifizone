<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Currency;
use App\Services\AuditLogger;
use App\Support\ApiResponse;
use DateTimeZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Réglages régionaux du tenant : langue par défaut, devise d'affichage par défaut, pays, fuseau.
 * Changer la devise par défaut ne convertit ni ne modifie AUCUN prix, vente ou total existant.
 */
class TenantSettingsController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:120'],
            'locale' => ['sometimes', Rule::in(config('wifizone.locales'))],
            'currency' => ['sometimes', 'string', Rule::exists('currencies', 'code')->where('is_active', true)],
            'country' => ['sometimes', 'string', 'regex:/^[A-Z]{2}$/'],
            'timezone' => ['sometimes', 'string', Rule::in(DateTimeZone::listIdentifiers())],
        ]);

        $tenant = $request->user()->tenant;
        $tenant->fill($data)->save();
        $this->audit->log('tenant.settings_updated', null, $tenant, ['fields' => array_keys($data)]);

        return ApiResponse::ok(TenantController::payload($tenant->refresh()));
    }
}
