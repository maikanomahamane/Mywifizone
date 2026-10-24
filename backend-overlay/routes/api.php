<?php

use App\Http\Controllers\Api\V1\Admin\AuditLogAdminController;
use App\Http\Controllers\Api\V1\Admin\PaymentAdminController;
use App\Http\Controllers\Api\V1\Admin\PaymentProviderAdminController;
use App\Http\Controllers\Api\V1\Admin\PlanAdminController;
use App\Http\Controllers\Api\V1\Admin\PlatformAdminController;
use App\Http\Controllers\Api\V1\Admin\PromotionAdminController;
use App\Http\Controllers\Api\V1\Admin\TenantAdminController;
use App\Http\Controllers\Api\V1\AccessPointController;
use App\Http\Controllers\Api\V1\TicketPdfController;
use App\Http\Controllers\Api\V1\ExportController;
use App\Http\Controllers\Api\V1\DocumentController;
use App\Http\Controllers\Api\V1\BrandingController;
use App\Http\Controllers\Api\V1\StaffController;
use App\Http\Controllers\Api\V1\SaleController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\CashSessionController;
use App\Http\Controllers\Api\V1\CashRegisterController;
use App\Http\Controllers\Api\V1\AgentController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\HotspotProfileController;
use App\Http\Controllers\Api\V1\InvoiceController;
use App\Http\Controllers\Api\V1\NetworkEventController;
use App\Http\Controllers\Api\V1\ExternalLinkController;
use App\Http\Controllers\Api\V1\PaymentWebhookController;
use App\Http\Controllers\Api\V1\PortalAdminController;
use App\Http\Controllers\Api\V1\PortalPublicController;
use App\Http\Controllers\Api\V1\PlanController;
use App\Http\Controllers\Api\V1\RouterController;
use App\Http\Controllers\Api\V1\SessionController;
use App\Http\Controllers\Api\V1\SiteController;
use App\Http\Controllers\Api\V1\SubscriptionController;
use App\Http\Controllers\Api\V1\CurrencyController;
use App\Http\Controllers\Api\V1\TenantController;
use App\Http\Controllers\Api\V1\TenantSettingsController;
use App\Http\Controllers\Api\V1\VoucherBatchController;
use App\Http\Controllers\Api\V1\VoucherController;
use App\Support\ApiResponse;
use Illuminate\Support\Facades\Route;

// Préfixe /api ajouté par le framework => /api/v1/...
Route::prefix('v1')->group(function () {
    Route::get('health', fn () => ApiResponse::ok(['status' => 'ok']));
    Route::get('health/ready', [\App\Http\Controllers\Api\V1\OpsController::class, 'ready'])->middleware('throttle:ops');
    Route::get('internal/metrics', [\App\Http\Controllers\Api\V1\OpsController::class, 'metrics'])->middleware('throttle:ops');

    // Catalogue public des formules (écran d'inscription)
    Route::get('plans', [PlanController::class, 'index']);

    // Webhooks fournisseurs : pas d'authentification, signature vérifiée par l'adaptateur
    Route::post('payments/webhooks/{provider}', PaymentWebhookController::class)
        ->where('provider', '[a-z_]{2,32}')
        ->middleware('throttle:webhooks');

    // Portail captif (Phase 7) : PUBLIC. Le tenant vient du slug public ou du jeton de commande, jamais d'un paramètre libre.
    Route::prefix('portal')->middleware('throttle:portal')->group(function () {
        Route::get('orders/{token}', [PortalPublicController::class, 'orderStatus'])->where('token', '[a-z0-9]{32}');
        Route::get('{slug}', [PortalPublicController::class, 'show'])->where('slug', '[a-z0-9]{8,24}');
        Route::get('{slug}/page', [PortalPublicController::class, 'page'])->where('slug', '[a-z0-9]{8,24}');
        Route::get('{slug}/logo', [PortalPublicController::class, 'logo'])->where('slug', '[a-z0-9]{8,24}');
        Route::post('{slug}/orders', [PortalPublicController::class, 'createOrder'])->where('slug', '[a-z0-9]{8,24}')->middleware('throttle:portal-orders');
    });

    // API de l'agent routeur (connexion sortante). Authentifié par jeton d'agent, pas par utilisateur.
    Route::prefix('agent')->group(function () {
        Route::post('enroll', [AgentController::class, 'enroll'])->middleware('throttle:agent-enroll');

        Route::middleware(['agent', 'throttle:agent'])->group(function () {
            Route::post('heartbeat', [AgentController::class, 'heartbeat']);
            Route::post('poll', [AgentController::class, 'poll']);
            Route::post('commands/{id}/result', [AgentController::class, 'result'])->whereUuid('id');
        });
    });

    Route::prefix('auth')->group(function () {
        Route::middleware('throttle:auth')->group(function () {
            Route::post('register', [AuthController::class, 'register']);
            Route::post('login', [AuthController::class, 'login']);
            Route::post('refresh', [AuthController::class, 'refresh']);
        });

        Route::middleware('auth:sanctum')->group(function () {
            Route::post('logout', [AuthController::class, 'logout']);
            Route::get('me', [AuthController::class, 'me']);
        });
    });

    // Routes métier : authentifié + tenant résolu depuis l'utilisateur (jamais depuis la requête).
    // Les ressources sont chargées par id explicite (findOrFail sous scope tenant) et non par route model binding,
    // dont l'ordre d'exécution par rapport au middleware `tenant` n'est pas garanti.
    Route::middleware(['auth:sanctum', 'tenant'])->group(function () {
        Route::get('tenants/me', [TenantController::class, 'show']);
        Route::put('tenants/me', [TenantSettingsController::class, 'update'])->middleware(['permission:tenant.manage', 'subscription.active']);

        // Portail captif et services externes (Phase 7)
        Route::get('portal-settings', [PortalAdminController::class, 'show'])->middleware('permission:tenant.manage');
        Route::put('portal-settings', [PortalAdminController::class, 'update'])->middleware(['permission:tenant.manage', 'subscription.active']);
        Route::get('portal-orders', [PortalAdminController::class, 'orders'])->middleware('permission:reports.view');
        Route::get('external-links', [ExternalLinkController::class, 'index']);
        Route::post('external-links', [ExternalLinkController::class, 'store'])->middleware(['permission:tenant.manage', 'subscription.active']);
        Route::put('external-links/{id}', [ExternalLinkController::class, 'update'])->whereUuid('id')->middleware(['permission:tenant.manage', 'subscription.active']);
        Route::delete('external-links/{id}', [ExternalLinkController::class, 'destroy'])->whereUuid('id')->middleware(['permission:tenant.manage', 'subscription.active']);

        // Devises (Phase 6) : liste ouverte à tout utilisateur du tenant ; taux et conversion = fonction `multi_currency` (PRO/BUSINESS).
        Route::get('currencies', [CurrencyController::class, 'index']);
        Route::get('currencies/convert', [CurrencyController::class, 'convert'])->middleware('throttle:60,1');
        Route::get('exchange-rates', [CurrencyController::class, 'rates'])->middleware('permission:tenant.manage');
        Route::put('exchange-rates', [CurrencyController::class, 'upsertRate'])->middleware(['permission:tenant.manage', 'subscription.active']);
        Route::delete('exchange-rates/{id}', [CurrencyController::class, 'destroyRate'])->whereUuid('id')->middleware(['permission:tenant.manage', 'subscription.active']);

        Route::get('sites', [SiteController::class, 'index'])->middleware('permission:sites.view');
        Route::get('sites/{id}', [SiteController::class, 'show'])->whereUuid('id')->middleware('permission:sites.view');
        Route::post('sites', [SiteController::class, 'store'])->middleware(['permission:sites.manage', 'subscription.active']);
        Route::put('sites/{id}', [SiteController::class, 'update'])->whereUuid('id')->middleware(['permission:sites.manage', 'subscription.active']);
        Route::delete('sites/{id}', [SiteController::class, 'destroy'])->whereUuid('id')->middleware(['permission:sites.manage', 'subscription.active']);

        // Réseau WiFi (Phase 3). Lectures : toujours possibles ; écritures : abonnement actif requis.
        $write = 'subscription.active';

        Route::get('routers', [RouterController::class, 'index'])->middleware('permission:routers.view');
        Route::get('routers/{id}', [RouterController::class, 'show'])->whereUuid('id')->middleware('permission:routers.view');
        Route::post('routers', [RouterController::class, 'store'])->middleware(['permission:routers.manage', $write]);
        Route::put('routers/{id}', [RouterController::class, 'update'])->whereUuid('id')->middleware(['permission:routers.manage', $write]);
        Route::delete('routers/{id}', [RouterController::class, 'destroy'])->whereUuid('id')->middleware(['permission:routers.manage', $write]);
        Route::post('routers/{id}/rotate-agent-token', [RouterController::class, 'rotateAgentToken'])->whereUuid('id')->middleware(['permission:routers.manage', $write]);
        Route::post('routers/{id}/sync', [RouterController::class, 'sync'])->whereUuid('id')->middleware(['permission:routers.manage', $write]);
        Route::get('routers/{id}/commands', [RouterController::class, 'commands'])->whereUuid('id')->middleware('permission:routers.manage');
        Route::post('routers/{id}/sessions/refresh', [RouterController::class, 'refreshSessions'])->whereUuid('id')->middleware('permission:sessions.view');

        Route::get('access-points', [AccessPointController::class, 'index'])->middleware('permission:routers.view');
        Route::get('access-points/{id}', [AccessPointController::class, 'show'])->whereUuid('id')->middleware('permission:routers.view');
        Route::post('access-points', [AccessPointController::class, 'store'])->middleware(['permission:routers.manage', $write]);
        Route::put('access-points/{id}', [AccessPointController::class, 'update'])->whereUuid('id')->middleware(['permission:routers.manage', $write]);
        Route::delete('access-points/{id}', [AccessPointController::class, 'destroy'])->whereUuid('id')->middleware(['permission:routers.manage', $write]);

        Route::get('profiles', [HotspotProfileController::class, 'index'])->middleware('permission:profiles.view');
        Route::get('profiles/{id}', [HotspotProfileController::class, 'show'])->whereUuid('id')->middleware('permission:profiles.view');
        Route::post('profiles', [HotspotProfileController::class, 'store'])->middleware(['permission:profiles.manage', $write]);
        Route::put('profiles/{id}', [HotspotProfileController::class, 'update'])->whereUuid('id')->middleware(['permission:profiles.manage', $write]);
        Route::delete('profiles/{id}', [HotspotProfileController::class, 'destroy'])->whereUuid('id')->middleware(['permission:profiles.manage', $write]);

        Route::get('voucher-batches', [VoucherBatchController::class, 'index'])->middleware('permission:tickets.view');
        Route::get('voucher-batches/{id}', [VoucherBatchController::class, 'show'])->whereUuid('id')->middleware('permission:tickets.view');
        Route::post('voucher-batches', [VoucherBatchController::class, 'store'])->middleware(['permission:tickets.create', $write]);

        Route::get('vouchers', [VoucherController::class, 'index'])->middleware('permission:tickets.view');
        Route::get('vouchers/{id}', [VoucherController::class, 'show'])->whereUuid('id')->middleware('permission:tickets.view');
        // Révoquer et déconnecter restent possibles même abonnement expiré : c'est une mesure de sécurité.
        Route::post('vouchers/{id}/revoke', [VoucherController::class, 'revoke'])->whereUuid('id')->middleware('permission:tickets.revoke');

        Route::get('sessions', [SessionController::class, 'index'])->middleware('permission:sessions.view');
        Route::post('sessions/{id}/disconnect', [SessionController::class, 'disconnect'])->whereUuid('id')->middleware('permission:sessions.disconnect');

        Route::get('network-events', [NetworkEventController::class, 'index'])->middleware('permission:routers.view');

        // Ventes, caisse, rapports, agents (Phase 4). Annuler/clôturer restent possibles abonnement expiré (correction, sécurité).
        Route::get('dashboard', [DashboardController::class, 'show'])->middleware('permission:sales.view');

        Route::get('sales', [SaleController::class, 'index'])->middleware('permission:sales.view');
        Route::get('sales/{id}', [SaleController::class, 'show'])->whereUuid('id')->middleware('permission:sales.view');
        Route::post('sales', [SaleController::class, 'store'])->middleware(['permission:sales.create', $write]);
        Route::post('sales/{id}/void', [SaleController::class, 'void'])->whereUuid('id')->middleware('permission:sales.void');

        Route::get('cash-registers', [CashRegisterController::class, 'index'])->middleware('permission:cash.view');
        Route::post('cash-registers', [CashRegisterController::class, 'store'])->middleware(['permission:cash.manage', $write]);
        Route::put('cash-registers/{id}', [CashRegisterController::class, 'update'])->whereUuid('id')->middleware(['permission:cash.manage', $write]);

        Route::get('cash-sessions', [CashSessionController::class, 'index'])->middleware('permission:cash.view');
        Route::get('cash-sessions/current', [CashSessionController::class, 'current'])->middleware('permission:cash.view');
        Route::post('cash-sessions', [CashSessionController::class, 'open'])->middleware(['permission:cash.operate', $write]);
        Route::get('cash-sessions/{id}', [CashSessionController::class, 'show'])->whereUuid('id')->middleware('permission:cash.view');
        Route::get('cash-sessions/{id}/transactions', [CashSessionController::class, 'transactions'])->whereUuid('id')->middleware('permission:cash.view');
        Route::post('cash-sessions/{id}/transactions', [CashSessionController::class, 'record'])->whereUuid('id')->middleware(['permission:cash.operate', $write]);
        Route::post('cash-sessions/{id}/close', [CashSessionController::class, 'close'])->whereUuid('id')->middleware('permission:cash.operate');

        Route::get('reports/sales', [ReportController::class, 'sales'])->middleware('permission:reports.view');

        Route::get('agents', [StaffController::class, 'index'])->middleware('permission:agents.manage');
        Route::get('agents/{id}', [StaffController::class, 'show'])->whereUuid('id')->middleware('permission:agents.manage');
        Route::post('agents', [StaffController::class, 'store'])->middleware(['permission:agents.manage', $write]);
        Route::put('agents/{id}', [StaffController::class, 'update'])->whereUuid('id')->middleware('permission:agents.manage');

        // Documents (Phase 5) : PDF de tickets, reçus, factures, export CSV, personnalisation. Lectures : abonnement expiré toléré.
        Route::post('tickets/pdf', [TicketPdfController::class, 'store'])->middleware(['permission:tickets.export', 'throttle:documents']);
        Route::get('sales/{id}/receipt', [DocumentController::class, 'receipt'])->whereUuid('id')->middleware(['permission:sales.view', 'throttle:documents']);
        Route::get('invoices/{id}/pdf', [DocumentController::class, 'invoice'])->whereUuid('id')->middleware(['permission:subscription.view', 'throttle:documents']);
        Route::get('exports/sales.csv', [ExportController::class, 'sales'])->middleware(['permission:reports.view', 'throttle:documents']);

        Route::get('branding', [BrandingController::class, 'show'])->middleware('permission:tickets.view');
        Route::put('branding', [BrandingController::class, 'update'])->middleware(['permission:tenant.manage', $write]);
        Route::get('branding/logo', [BrandingController::class, 'logo'])->middleware('permission:tickets.view');
        Route::post('branding/logo', [BrandingController::class, 'uploadLogo'])->middleware(['permission:tenant.manage', $write, 'throttle:10,1']);
        Route::delete('branding/logo', [BrandingController::class, 'deleteLogo'])->middleware(['permission:tenant.manage', $write]);

        // Abonnement et facturation (un abonnement expiré doit pouvoir consulter et payer)
        Route::get('subscription', [SubscriptionController::class, 'show'])->middleware('permission:subscription.view');
        Route::get('subscription/providers', [SubscriptionController::class, 'providers'])->middleware('permission:subscription.view');
        Route::post('subscription/checkout', [SubscriptionController::class, 'checkout'])->middleware('permission:subscription.manage');
        Route::post('subscription/cancel', [SubscriptionController::class, 'cancel'])->middleware('permission:subscription.manage');
        Route::get('invoices', [InvoiceController::class, 'index'])->middleware('permission:subscription.view');
        Route::get('invoices/{id}', [InvoiceController::class, 'show'])->whereUuid('id')->middleware('permission:subscription.view');
    });

    // Super Admin (hors tenant). `permission:*` vérifie aussi que le compte est actif.
    Route::prefix('admin')->middleware(['auth:sanctum'])->group(function () {
        Route::middleware('permission:admin.plans.manage')->group(function () {
            Route::get('plans', [PlanAdminController::class, 'index']);
            Route::put('plans/{id}', [PlanAdminController::class, 'update'])->whereUuid('id');
            Route::get('promotions', [PromotionAdminController::class, 'index']);
            Route::post('promotions', [PromotionAdminController::class, 'store']);
            Route::put('promotions/{id}', [PromotionAdminController::class, 'update'])->whereUuid('id');
        });

        Route::middleware('permission:admin.providers.manage')->group(function () {
            Route::get('payment-providers', [PaymentProviderAdminController::class, 'index']);
            Route::put('payment-providers/{code}', [PaymentProviderAdminController::class, 'update'])->where('code', '[a-z_]{2,32}');
        });

        Route::middleware('permission:admin.tenants.manage')->group(function () {
            Route::get('tenants', [TenantAdminController::class, 'index']);
            Route::post('tenants/{id}/suspend', [TenantAdminController::class, 'suspend'])->whereUuid('id');
            Route::post('tenants/{id}/reactivate', [TenantAdminController::class, 'reactivate'])->whereUuid('id');
        });

        Route::get('overview', [PlatformAdminController::class, 'overview'])->middleware('permission:admin.platform.view');
        Route::get('payments', [PaymentAdminController::class, 'index'])->middleware('permission:admin.payments.view');
        Route::get('audit-logs', [AuditLogAdminController::class, 'index'])->middleware('permission:admin.audit.view');
    });
});
