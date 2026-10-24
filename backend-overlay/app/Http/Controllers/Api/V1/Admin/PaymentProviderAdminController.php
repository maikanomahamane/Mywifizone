<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Infrastructure\Payments\PaymentManager;
use App\Infrastructure\Payments\UnconfiguredProvider;
use App\Models\PaymentProviderSetting;
use App\Services\AuditLogger;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentProviderAdminController extends Controller
{
    public function __construct(
        private readonly PaymentManager $manager,
        private readonly AuditLogger $audit,
    ) {}

    public function index(): JsonResponse
    {
        return ApiResponse::ok(PaymentProviderSetting::orderBy('code')->get()->map(fn ($s) => $this->payload($s))->all());
    }

    /** Activer un fournisseur ne le rend pas utilisable : il faut aussi que ses identifiants soient configurés (`credentials_configured`). */
    public function update(Request $request, string $code): JsonResponse
    {
        $setting = PaymentProviderSetting::findOrFail($code);

        $data = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'countries' => ['sometimes', 'nullable', 'array'],
            'countries.*' => ['string', 'size:2'],
            'currencies' => ['sometimes', 'nullable', 'array'],
            'currencies.*' => ['string', 'size:3'],
        ]);

        $setting->update($data);
        $this->audit->log('payment_provider.updated', null, null, ['provider' => $code, 'changes' => array_keys($data)]);

        return ApiResponse::ok($this->payload($setting));
    }

    /** @return array<string, mixed> */
    private function payload(PaymentProviderSetting $setting): array
    {
        $adapter = $this->manager->get($setting->code);

        return [
            'code' => $setting->code,
            'name' => $setting->name,
            'enabled' => $setting->enabled,
            'countries' => $setting->countries,
            'currencies' => $setting->currencies,
            'credentials_configured' => $adapter?->isConfigured() ?? false,
            'required_env' => $adapter instanceof UnconfiguredProvider ? $adapter->requiredEnv() : [],
        ];
    }
}
