<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionPlan;
use App\Services\AuditLogger;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Super Admin : prix, limites, fonctionnalités, durées d'essai/grâce.
 * Un changement ne touche que les FUTURES factures ; les factures déjà émises et les périodes payées restent intactes.
 */
class PlanAdminController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): JsonResponse
    {
        $plans = SubscriptionPlan::orderBy('sort_order')->get();

        return ApiResponse::ok($plans->map(fn (SubscriptionPlan $p) => $this->payload($p))->all());
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $plan = SubscriptionPlan::findOrFail($id);

        // Normalisé AVANT validation : la règle `exists` est sensible à la casse (« xof » serait refusé).
        if (is_string($request->input('currency'))) {
            $request->merge(['currency' => strtoupper(trim($request->input('currency')))]);
        }

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:80'],
            'monthly_price' => ['sometimes', 'integer', 'min:0'],
            'yearly_price' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'currency' => ['sometimes', 'string', Rule::exists('currencies', 'code')->where('is_active', true)],
            'trial_days' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:365'],
            'grace_days' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:60'],
            'limits' => ['sometimes', 'array:max_sites,max_routers,max_access_points,max_agents,max_batch_size'],
            'limits.*' => ['nullable', 'integer', 'min:0'], // null = illimité
            'features' => ['sometimes', 'array'],
            'features.*' => ['string', 'max:40'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (isset($data['currency'])) {
            $data['currency'] = strtoupper($data['currency']);
        }
        if (isset($data['limits'])) {
            $data['limits'] = array_merge($plan->limits ?? [], $data['limits']);
        }

        $before = $plan->only(array_keys($data));
        $plan->update($data);

        $this->audit->log('plan.updated', null, $plan, ['plan' => $plan->code, 'before' => $before, 'after' => $plan->only(array_keys($data))]);

        return ApiResponse::ok($this->payload($plan));
    }

    /** @return array<string, mixed> */
    private function payload(SubscriptionPlan $plan): array
    {
        return ['id' => $plan->getKey(), 'is_active' => $plan->is_active, 'grace_days' => $plan->graceDays()] + $plan->toPublicArray();
    }
}
