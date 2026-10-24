<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Billing\SubscriptionStatus;
use App\Domain\Payments\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\PaymentTransaction;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Super Admin : tableau de bord plateforme (clients, abonnements, chiffre d'affaires SaaS, santé). */
class PlatformAdminController extends Controller
{
    public function overview(): JsonResponse
    {
        $tenants = array_fill_keys([Tenant::STATUS_ACTIVE, Tenant::STATUS_SUSPENDED], 0);
        foreach (Tenant::query()->selectRaw('status, count(*) as n')->groupBy('status')->get() as $row) {
            $tenants[$row->status] = (int) $row->n;
        }

        $subscriptions = array_fill_keys([
            SubscriptionStatus::TRIAL, SubscriptionStatus::ACTIVE, SubscriptionStatus::PENDING, SubscriptionStatus::PAST_DUE,
            SubscriptionStatus::EXPIRED, SubscriptionStatus::SUSPENDED, SubscriptionStatus::CANCELLED,
        ], 0);
        foreach (Subscription::withoutGlobalScopes()->selectRaw('status, count(*) as n')->groupBy('status')->get() as $row) {
            $subscriptions[$row->status] = (int) $row->n;
        }

        // CA SaaS encaissé : factures payées, par devise (jamais d'addition entre devises différentes).
        $revenue = [];
        foreach (Invoice::withoutGlobalScopes()->where('status', Invoice::PAID)->selectRaw('currency, count(*) as n, sum(total) as total')->groupBy('currency')->get() as $row) {
            $revenue[] = ['currency' => $row->currency, 'invoices' => (int) $row->n, 'total' => (int) $row->total];
        }

        return ApiResponse::ok([
            'tenants' => $tenants,
            'users' => User::query()->whereNotNull('tenant_id')->count(),
            'subscriptions' => $subscriptions,
            'revenue' => $revenue,
            'payments_in_flight' => PaymentTransaction::withoutGlobalScopes()
                ->whereIn('status', [PaymentStatus::INITIATED, PaymentStatus::PENDING])->count(),
            'health' => ['database' => $this->check(fn () => DB::select('select 1')), 'cache' => $this->check(function () {
                Cache::put('health:ping', '1', 10);

                return Cache::get('health:ping') === '1';
            })],
        ]);
    }

    private function check(callable $probe): bool
    {
        try {
            return $probe() !== false;
        } catch (Throwable) {
            return false;
        }
    }
}
