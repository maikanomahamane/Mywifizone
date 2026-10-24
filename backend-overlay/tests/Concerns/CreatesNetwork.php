<?php

namespace Tests\Concerns;

use App\Models\HotspotProfile;
use App\Models\Router;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\Voucher;
use App\Services\RouterService;

/** Fabrique du réseau de test (sites, routeurs enrôlés, profils) sans passer par l'API. */
trait CreatesNetwork
{
    protected function makeSite(Tenant $tenant): Site
    {
        return $this->asTenant($tenant, fn () => Site::factory()->create());
    }

    /** @return array{0: Router, 1: string} routeur non enrôlé et son code d'enrôlement */
    protected function makeRouter(Tenant $tenant, ?Site $site = null): array
    {
        $site ??= $this->makeSite($tenant);

        return $this->asTenant($tenant, fn () => app(RouterService::class)->create([
            'site_id' => $site->getKey(),
            'name' => 'Routeur test',
        ]));
    }

    /** @return array{0: Router, 1: string} routeur enrôlé et le jeton de son agent */
    protected function enrolledRouter(Tenant $tenant, ?Site $site = null): array
    {
        [, $code] = $this->makeRouter($tenant, $site);
        [$router, $token] = app(RouterService::class)->enroll($code, ['agent_version' => 'test']);

        return [$router, $token];
    }

    protected function makeProfile(Tenant $tenant, array $attrs = []): HotspotProfile
    {
        return $this->asTenant($tenant, fn () => HotspotProfile::create($attrs + [
            'name' => '1 heure',
            'kind' => 'STANDARD',
            'price' => 200,
            'currency' => 'XOF',
            'duration_minutes' => 60,
            'rate_limit' => '2M/2M',
            'device_limit' => 1,
        ]));
    }

    /** Voucher en stock (confirmé par le routeur) : AVAILABLE + SYNCED, snapshot du profil. */
    protected function makeVoucher(Tenant $tenant, Router $router, HotspotProfile $profile, array $attrs = []): Voucher
    {
        static $n = 0;
        $n++;

        return $this->asTenant($tenant, fn () => Voucher::create($attrs + [
            'site_id' => $router->site_id,
            'router_id' => $router->getKey(),
            'profile_id' => $profile->getKey(),
            'code' => sprintf('TST%05d', $n),
            'status' => 'AVAILABLE',
            'sync_status' => Voucher::SYNC_SYNCED,
            'price' => $profile->price,
            'currency' => $profile->currency,
            'duration_minutes' => $profile->duration_minutes,
            'rate_limit' => $profile->rate_limit,
            'device_limit' => $profile->device_limit,
            'is_vip' => false,
        ]));
    }
}
