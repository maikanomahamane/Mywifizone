<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Billing\PromotionType;
use App\Http\Controllers\Controller;
use App\Models\Promotion;
use App\Services\AuditLogger;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PromotionAdminController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): JsonResponse
    {
        return ApiResponse::ok(Promotion::orderByDesc('created_at')->get()->map(fn (Promotion $p) => $this->payload($p))->all());
    }

    public function store(Request $request): JsonResponse
    {
        // Normalisé AVANT validation : sinon « rentree » passerait le contrôle d'unicité face à « RENTREE ».
        if (is_string($request->input('code'))) {
            $request->merge(['code' => strtoupper(trim($request->input('code')))]);
        }

        $data = $request->validate([
            'code' => ['required', 'string', 'max:40', 'alpha_dash', Rule::unique('promotions', 'code')],
            'type' => ['required', Rule::in(PromotionType::ALL)],
            'value' => ['required', 'integer', 'min:1'],
            'currency' => ['nullable', 'string', Rule::exists('currencies', 'code')],
            'plan_code' => ['nullable', 'string', Rule::exists('subscription_plans', 'code')],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
            'max_redemptions' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $data['code'] = strtoupper($data['code']);
        $this->assertCoherent($data['type'], $data);

        $promotion = Promotion::create($data);
        $this->audit->log('promotion.created', null, $promotion, ['code' => $promotion->code, 'type' => $promotion->type, 'value' => $promotion->value]);

        return ApiResponse::ok($this->payload($promotion), status: 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $promotion = Promotion::findOrFail($id);

        $data = $request->validate([
            'value' => ['sometimes', 'integer', 'min:1'],
            'plan_code' => ['sometimes', 'nullable', 'string', Rule::exists('subscription_plans', 'code')],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date'],
            'max_redemptions' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $this->assertCoherent($promotion->type, $data + $promotion->only(['value', 'currency', 'starts_at', 'ends_at']));

        $promotion->update($data);
        $this->audit->log('promotion.updated', null, $promotion, ['code' => $promotion->code, 'changes' => array_keys($data)]);

        return ApiResponse::ok($this->payload($promotion));
    }

    /** @param array<string, mixed> $data */
    private function assertCoherent(string $type, array $data): void
    {
        if ($type === PromotionType::PERCENTAGE && (int) $data['value'] > 100) {
            throw ValidationException::withMessages(['value' => ['Un pourcentage ne peut pas dépasser 100.']]);
        }
        if ($type === PromotionType::FIXED && empty($data['currency'])) {
            throw ValidationException::withMessages(['currency' => ['La devise est requise pour une remise fixe.']]);
        }
        if (! empty($data['starts_at']) && ! empty($data['ends_at']) && strtotime((string) $data['ends_at']) <= strtotime((string) $data['starts_at'])) {
            throw ValidationException::withMessages(['ends_at' => ['La fin doit suivre le début.']]);
        }
    }

    /** @return array<string, mixed> */
    private function payload(Promotion $p): array
    {
        return [
            'id' => $p->getKey(), 'code' => $p->code, 'type' => $p->type, 'value' => $p->value, 'currency' => $p->currency,
            'plan_code' => $p->plan_code, 'starts_at' => $p->starts_at?->toIso8601String(), 'ends_at' => $p->ends_at?->toIso8601String(),
            'max_redemptions' => $p->max_redemptions, 'redemptions_count' => $p->redemptions_count, 'is_active' => $p->is_active,
        ];
    }
}
