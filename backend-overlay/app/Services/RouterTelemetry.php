<?php

namespace App\Services;

use App\Domain\Network\RouterStatus;
use App\Domain\Network\VoucherStatus;
use App\Models\HotspotSession;
use App\Models\NetworkEvent;
use App\Models\Router;
use App\Models\Voucher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ingestion de ce que l'agent remonte : signal de vie, santé, sessions actives, vouchers épuisés.
 * Appelé aussi bien par le heartbeat que par le résultat d'une commande FETCH_* : une seule logique.
 * Les entrées doivent déjà être validées (types, tailles) par l'appelant.
 */
final class RouterTelemetry
{
    /** Signal de vie : le routeur passe ONLINE (événement seulement sur changement d'état). */
    public function markSeen(Router $router): void
    {
        $was = $router->status;
        $router->forceFill(['last_seen_at' => now(), 'status' => RouterStatus::ONLINE])->save();

        if ($was !== RouterStatus::ONLINE) {
            NetworkEvent::record($router->tenant_id, $router->getKey(), 'ROUTER_ONLINE', 'INFO', null, ['was' => $was]);
        }
    }

    /** @param array<string, mixed> $health */
    public function ingestHealth(Router $router, array $health): void
    {
        $allowed = array_intersect_key($health, array_flip([
            'board_name', 'version', 'cpu_load', 'free_memory', 'total_memory', 'uptime_seconds', 'agent_version',
        ]));

        $router->forceFill([
            'health' => $allowed ?: null,
            'routeros_version' => isset($allowed['version']) ? mb_substr((string) $allowed['version'], 0, 40) : $router->routeros_version,
            'model' => $router->model ?? (isset($allowed['board_name']) ? mb_substr((string) $allowed['board_name'], 0, 80) : null),
        ])->save();
    }

    /**
     * Remplace l'instantané des sessions actives du routeur, active les vouchers vus en session et marque comme
     * épuisés ceux que le routeur signale.
     *
     * @param  array<int, array<string, mixed>>  $sessions  chaque entrée : id, username, address?, mac?, uptime_seconds?, bytes_in?, bytes_out?
     * @param  array<int, string>  $finishedCodes
     */
    public function ingestSessions(Router $router, array $sessions, array $finishedCodes = []): void
    {
        DB::transaction(function () use ($router, $sessions, $finishedCodes) {
            $now = now();
            // Seulement les sessions encore actives et celles dont l'identifiant revient (l'historique terminé reste hors de la requête).
            $incomingIds = collect($sessions)->pluck('id')->map(fn ($id) => (string) $id)->all();
            $existing = HotspotSession::where('router_id', $router->getKey())
                ->where(fn ($q) => $q->whereNull('ended_at')->orWhereIn('remote_id', $incomingIds))
                ->get()->keyBy('remote_id');

            $fresh = [];
            $continuing = [];
            $seen = [];

            foreach ($sessions as $session) {
                $remoteId = (string) $session['id'];
                $seen[$remoteId] = true;
                $uptime = (int) ($session['uptime_seconds'] ?? 0);
                $current = $existing->get($remoteId);

                $row = [
                    'tenant_id' => $router->tenant_id,
                    'router_id' => $router->getKey(),
                    'remote_id' => $remoteId,
                    'username' => (string) $session['username'],
                    'ip' => $session['address'] ?? null,
                    'mac' => isset($session['mac']) ? strtoupper($session['mac']) : null,
                    'uptime_seconds' => $uptime,
                    'bytes_in' => (int) ($session['bytes_in'] ?? 0),
                    'bytes_out' => (int) ($session['bytes_out'] ?? 0),
                    'last_seen_at' => $now,
                    'ended_at' => null,
                    'updated_at' => $now,
                ];

                // Nouvelle session, ou identifiant RouterOS réutilisé après la fin d'une session précédente.
                $isNew = $current === null || $current->ended_at !== null;
                if ($isNew) {
                    $fresh[] = $row + ['id' => (string) Str::uuid(), 'started_at' => $now->copy()->subSeconds($uptime), 'created_at' => $now];
                } else {
                    $continuing[] = $row + ['id' => $current->getKey(), 'created_at' => $now];
                }
            }

            $columns = ['username', 'ip', 'mac', 'uptime_seconds', 'bytes_in', 'bytes_out', 'last_seen_at', 'ended_at', 'updated_at'];
            foreach (array_chunk($fresh, 50) as $chunk) {
                HotspotSession::upsert($chunk, ['router_id', 'remote_id'], [...$columns, 'started_at']);
            }
            foreach (array_chunk($continuing, 50) as $chunk) {
                HotspotSession::upsert($chunk, ['router_id', 'remote_id'], $columns);
            }

            // Absentes du relevé = terminées.
            $gone = $existing->filter(fn ($s) => $s->ended_at === null && ! isset($seen[$s->remote_id]))->keys()->all();
            if ($gone !== []) {
                HotspotSession::where('router_id', $router->getKey())->whereIn('remote_id', $gone)->update(['ended_at' => $now]);
            }

            $router->forceFill(['clients_count' => count($seen)])->save();

            $this->syncVouchers($router, collect($sessions)->pluck('username')->unique()->values()->all(), $finishedCodes, $now);
        });
    }

    /** @param array<int, string> $usernames @param array<int, string> $finishedCodes */
    private function syncVouchers(Router $router, array $usernames, array $finishedCodes, $now): void
    {
        $base = fn () => Voucher::where('router_id', $router->getKey());

        if ($usernames !== []) {
            $base()->whereIn('code', $usernames)
                ->whereIn('status', [VoucherStatus::GENERATED, VoucherStatus::AVAILABLE, VoucherStatus::SOLD, VoucherStatus::VIP])
                ->update(['status' => VoucherStatus::ACTIVE, 'activated_at' => $now]);
        }

        if ($finishedCodes !== []) {
            $base()->whereIn('code', $finishedCodes)->whereNull('activated_at')->whereIn('status', VoucherStatus::LIVE)->update(['activated_at' => $now]);
            $base()->whereIn('code', $finishedCodes)->whereIn('status', VoucherStatus::LIVE)->update(['status' => VoucherStatus::USED]);
        }
    }

    /** Routeurs sans signal depuis trop longtemps : ONLINE -> OFFLINE (une fois), avec événement. Retourne le nombre. */
    public function markStaleOffline(): int
    {
        $count = 0;
        $limit = now()->subSeconds((int) config('network.offline_after_seconds'));

        Router::withoutGlobalScopes()
            ->where('status', RouterStatus::ONLINE)
            ->where('last_seen_at', '<', $limit)
            ->chunkById(200, function ($routers) use (&$count) {
                foreach ($routers as $router) {
                    $router->forceFill(['status' => RouterStatus::OFFLINE])->save();
                    NetworkEvent::record($router->tenant_id, $router->getKey(), 'ROUTER_OFFLINE', 'WARNING', 'Aucun signal du routeur.', [
                        'last_seen_at' => $router->last_seen_at?->toIso8601String(),
                    ]);
                    $count++;
                }
            });

        return $count;
    }
}
