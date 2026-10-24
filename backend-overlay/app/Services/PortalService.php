<?php

namespace App\Services;

use App\Domain\Network\VoucherStatus;
use App\Domain\Portal\PortalOrderStatus;
use App\Domain\Portal\PortalSlug;
use App\Domain\Tenancy\TenantContext;
use App\Models\Currency;
use App\Models\HotspotProfile;
use App\Models\PaymentTransaction;
use App\Models\PortalOrder;
use App\Models\Router;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantBranding;
use App\Models\Voucher;
use App\Support\ApiException;
use Illuminate\Database\UniqueConstraintViolationException;

/** Portail captif public : offres, commandes et statut. Tout s'exécute dans le contexte du tenant du portail (résolu par le slug public). */
final class PortalService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly PlanEntitlements $entitlements,
        private readonly PaymentService $payments,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array{tenant: Tenant, branding: TenantBranding} */
    public function resolve(string $slug): array
    {
        $branding = PortalSlug::isValid($slug)
            ? TenantBranding::withoutGlobalScopes()->where('portal_slug', $slug)->where('portal_enabled', true)->first()
            : null;
        $tenant = $branding ? Tenant::query()->find($branding->tenant_id) : null;

        if (! $branding || ! $tenant) {
            throw new ApiException('portal_not_found', 'Portail introuvable.', 404);
        }

        return ['tenant' => $tenant, 'branding' => $branding];
    }

    /** Le portail ne répond que si le tenant est actif, son abonnement valide et sa formule inclut `captive_portal`. */
    public function assertServing(Tenant $tenant): void
    {
        $status = Subscription::value('status');
        if ($tenant->status !== 'ACTIVE' || ! in_array($status, \App\Domain\Billing\SubscriptionStatus::WRITABLE, true) || ! $this->entitlements->hasFeature('captive_portal')) {
            throw new ApiException('portal_unavailable', 'Ce portail est indisponible.', 503);
        }
    }

    /** @return list<array<string, mixed>> */
    public function offers(?string $routerId): array
    {
        $minor = Currency::pluck('minor_unit', 'code');
        $profiles = HotspotProfile::where('portal_visible', true)->where('is_active', true)->where('kind', 'STANDARD')->where('price', '>', 0)->orderBy('price')->get();

        $stock = [];
        if ($routerId !== null) {
            $stock = Voucher::where('router_id', $routerId)->where('status', VoucherStatus::AVAILABLE)->where('sync_status', Voucher::SYNC_SYNCED)
                ->selectRaw('profile_id, count(*) as n')->groupBy('profile_id')->pluck('n', 'profile_id')->all();
        }

        return $profiles->map(fn (HotspotProfile $p) => [
            'id' => $p->getKey(), 'name' => $p->name, 'price' => $p->price, 'currency' => $p->currency, 'minor_unit' => (int) ($minor[$p->currency] ?? 2),
            'duration_minutes' => $p->duration_minutes, 'rate_limit' => $p->rate_limit, 'device_limit' => $p->device_limit,
            'available' => $routerId === null ? true : ((int) ($stock[$p->getKey()] ?? 0)) > 0,
        ])->values()->all();
    }

    /**
     * Crée la commande puis lance le paiement. Le voucher n'est PAS réservé ici (pas de stock bloqué par des paniers abandonnés) :
     * il est pris à la confirmation du paiement. Rejouable via `client_ref`.
     *
     * @param  array{profile_id: string, router_id: string, locale?: ?string, customer_phone?: ?string, client_ref?: ?string, provider?: ?string}  $data
     * @return array{0: PortalOrder, 1: PaymentTransaction}
     */
    public function createOrder(array $data): array
    {
        $ref = $data['client_ref'] ?? null;
        if ($ref !== null && ($existing = PortalOrder::where('client_ref', $ref)->first())) {
            $tx = PaymentTransaction::where('portal_order_id', $existing->getKey())->latest()->first();
            if ($tx) {
                return [$existing, $tx];
            }
        }

        $profile = HotspotProfile::where('portal_visible', true)->where('is_active', true)->where('kind', 'STANDARD')->find($data['profile_id']);
        $router = Router::find($data['router_id']);
        if (! $profile || ! $router || $profile->price <= 0) {
            throw new ApiException('offer_unavailable', 'Cette offre n\'est pas disponible.', 422);
        }

        $stock = Voucher::where('router_id', $router->getKey())->where('profile_id', $profile->getKey())
            ->where('status', VoucherStatus::AVAILABLE)->where('sync_status', Voucher::SYNC_SYNCED)->exists();
        if (! $stock) {
            throw new ApiException('sold_out', 'Plus de tickets disponibles pour ce pass.', 409);
        }

        if (PortalOrder::where('status', PortalOrderStatus::PENDING_PAYMENT)->count() >= config('portal.max_pending_orders')) {
            throw new ApiException('portal_busy', 'Trop de commandes en attente, réessayez plus tard.', 429);
        }

        $provider = $data['provider'] ?? ($this->payments->usableProviders($profile->currency)[0]['code'] ?? null);
        if ($provider === null) {
            throw new ApiException('provider_unavailable', 'Aucun moyen de paiement disponible.', 503);
        }
        $adapter = $this->payments->assertUsable($provider, $profile->currency); // avant toute écriture

        try {
            $order = PortalOrder::create([
                'router_id' => $router->getKey(),
                'profile_id' => $profile->getKey(),
                'public_token' => PortalSlug::token(),
                'client_ref' => $ref,
                'amount' => $profile->price,
                'currency' => $profile->currency,
                'locale' => \App\Domain\Portal\PortalLabels::normalize($data['locale'] ?? null),
                'customer_phone' => $data['customer_phone'] ?? null,
                'status' => PortalOrderStatus::PENDING_PAYMENT,
            ]);
        } catch (UniqueConstraintViolationException) {
            $existing = PortalOrder::where('client_ref', $ref)->firstOrFail();
            $tx = PaymentTransaction::where('portal_order_id', $existing->getKey())->latest()->first();
            if ($tx) {
                return [$existing, $tx];
            }
            throw new ApiException('order_in_progress', 'Commande en cours, réessayez.', 409);
        }

        try {
            $tx = $this->payments->startForOrder($order, $adapter, $provider, $data['customer_phone'] ?? null);
        } catch (ApiException $e) {
            $order->update(['status' => PortalOrderStatus::FAILED]);
            throw $e;
        }
        $this->audit->log('portal.order_created', null, $order, ['profile_id' => $profile->getKey(), 'amount' => $order->amount, 'currency' => $order->currency], $order->tenant_id);

        return [$order, $tx];
    }

    /** Vue publique d'une commande : le code n'est montré que livré, et pendant `code_reveal_hours`. */
    public function publicStatus(PortalOrder $order): array
    {
        $payload = ['status' => $order->status, 'reference' => substr((string) $order->getKey(), 0, 8), 'code' => null];
        if ($order->status === PortalOrderStatus::FULFILLED && $order->fulfilled_at
            && $order->fulfilled_at->gt(now()->subHours((int) config('portal.code_reveal_hours')))) {
            $payload['code'] = Voucher::find($order->voucher_id)?->code;
        }

        return $payload;
    }

    /** Résout une commande par son jeton secret, dans son contexte tenant. */
    public function findByToken(string $token): ?PortalOrder
    {
        if (! preg_match('/^[a-z0-9]{32}$/', $token)) {
            return null;
        }

        return PortalOrder::withoutGlobalScopes()->where('public_token', $token)->first();
    }

    public function run(string $tenantId, callable $fn): mixed
    {
        return $this->context->run($tenantId, $fn);
    }
}
