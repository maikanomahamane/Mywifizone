<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionPlan;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/** Catalogue public des formules actives (écran d'inscription, avant connexion). Prix lus en base, jamais codés en dur. */
class PlanController extends Controller
{
    public function index(): JsonResponse
    {
        $plans = SubscriptionPlan::where('is_active', true)->orderBy('sort_order')->get();

        return ApiResponse::ok($plans->map(fn (SubscriptionPlan $p) => $p->toPublicArray())->all());
    }
}
