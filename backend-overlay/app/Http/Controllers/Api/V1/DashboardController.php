<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Network\RouterStatus;
use App\Domain\Network\VoucherStatus;
use App\Http\Controllers\Controller;
use App\Models\Router;
use App\Models\Voucher;
use App\Services\CashService;
use App\Services\ReportService;
use App\Services\SaleService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(
        private readonly ReportService $reports,
        private readonly SaleService $sales,
        private readonly CashService $cash,
    ) {}

    /** Tableau de bord mobile : réseau, clients connectés, tickets actifs, stock, recettes du jour, caisse. */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $today = $this->sales->localDate($user->tenant_id, now());
        $seeAll = $user->hasPermission('sales.view_all');

        $routers = Router::query()->selectRaw('status, count(*) as n, coalesce(sum(clients_count), 0) as clients')->groupBy('status')->get()->keyBy('status');
        $vouchers = Voucher::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return ApiResponse::ok([
            'date' => $today,
            'network' => [
                'routers_online' => (int) ($routers[RouterStatus::ONLINE]->n ?? 0),
                'routers_offline' => (int) ($routers[RouterStatus::OFFLINE]->n ?? 0),
                'routers_pending' => (int) ($routers[RouterStatus::PENDING]->n ?? 0),
                'connected_clients' => (int) ($routers[RouterStatus::ONLINE]->clients ?? 0),
            ],
            'tickets' => [
                'active' => (int) ($vouchers[VoucherStatus::ACTIVE] ?? 0),
                'available' => (int) ($vouchers[VoucherStatus::AVAILABLE] ?? 0),
                'sold' => (int) ($vouchers[VoucherStatus::SOLD] ?? 0),
            ],
            // Un agent ne voit que ses propres recettes.
            'revenue_today' => $this->reports->dayRevenue($today, $seeAll ? null : $user->getKey()),
            'cash_session' => $this->cash->openSessionOf($user)?->toPayload(),
        ]);
    }
}
