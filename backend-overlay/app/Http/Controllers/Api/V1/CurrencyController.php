<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Money\ExchangeRate as Rate;
use App\Http\Controllers\Controller;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Services\AuditLogger;
use App\Services\CurrencyService;
use App\Services\PlanEntitlements;
use App\Support\ApiException;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/** Devises, taux indicatifs et conversion. Un taux ne modifie jamais un prix (cahier des charges §4). */
class CurrencyController extends Controller
{
    public function __construct(
        private readonly CurrencyService $currencies,
        private readonly PlanEntitlements $entitlements,
        private readonly AuditLogger $audit,
    ) {}

    public function index(): JsonResponse
    {
        $list = Currency::query()->where('is_active', true)->orderBy('code')->get()
            ->map(fn (Currency $c) => ['code' => $c->code, 'name' => $c->name, 'minor_unit' => $c->minor_unit, 'symbol' => $c->symbol]);

        return ApiResponse::ok($list->values());
    }

    public function rates(): JsonResponse
    {
        $this->entitlements->assertFeature('multi_currency');
        $manual = ExchangeRate::query()->orderBy('from_currency')->orderBy('to_currency')->get()->map->toPayload();
        $pegs = collect(Rate::PEGS)->map(fn ($rate, $pair) => [
            'id' => null, 'from' => explode(':', $pair)[0], 'to' => explode(':', $pair)[1], 'rate' => $rate, 'source' => 'peg', 'updated_at' => null,
        ])->values();

        return ApiResponse::ok($pegs->concat($manual)->values());
    }

    public function upsertRate(Request $request): JsonResponse
    {
        $request->merge([
            'from' => strtoupper((string) $request->input('from')),
            'to' => strtoupper((string) $request->input('to')),
        ]);
        $data = $request->validate([
            'from' => ['required', 'regex:/^[A-Z]{3}$/'],
            'to' => ['required', 'regex:/^[A-Z]{3}$/', 'different:from'],
            'rate' => ['required', 'string'],
        ]);
        $this->entitlements->assertFeature('multi_currency');
        $this->currencies->activeOrFail($data['from']);
        $this->currencies->activeOrFail($data['to']);

        if (isset(Rate::PEGS["{$data['from']}:{$data['to']}"])) {
            throw new ApiException('peg_rate_fixed', 'Parité fixe : non modifiable.', 422);
        }
        try {
            $scaled = Rate::parse($data['rate']);
        } catch (InvalidArgumentException) {
            throw new ApiException('rate_invalid', 'Taux invalide (nombre positif, 8 décimales maximum).', 422, ['field' => 'rate']);
        }

        $row = ExchangeRate::query()->updateOrCreate(
            ['from_currency' => $data['from'], 'to_currency' => $data['to']],
            ['rate_scaled' => $scaled],
        );
        $this->audit->log('exchange_rate.set', null, $row, ['from' => $data['from'], 'to' => $data['to'], 'rate' => Rate::format($scaled)]);

        return ApiResponse::ok($row->toPayload());
    }

    public function destroyRate(string $id): JsonResponse
    {
        $row = ExchangeRate::query()->findOrFail($id);
        $row->delete();
        $this->audit->log('exchange_rate.deleted', null, $row, ['from' => $row->from_currency, 'to' => $row->to_currency]);

        return ApiResponse::ok(['deleted' => true]);
    }

    public function convert(Request $request): JsonResponse
    {
        $request->merge([
            'from' => strtoupper((string) $request->input('from')),
            'to' => strtoupper((string) $request->input('to')),
        ]);
        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:0', 'max:999999999999'],
            'from' => ['required', 'regex:/^[A-Z]{3}$/'],
            'to' => ['required', 'regex:/^[A-Z]{3}$/'],
        ]);
        $this->entitlements->assertFeature('multi_currency');

        return ApiResponse::ok($this->currencies->convert((int) $data['amount'], $data['from'], $data['to']));
    }
}
