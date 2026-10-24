<?php

namespace App\Services;

use App\Domain\Network\CommandStatus;
use App\Domain\Network\CommandType;
use App\Domain\Network\RouterOsValue;
use App\Domain\Network\VoucherStatus;
use App\Models\HotspotProfile;
use App\Models\NetworkEvent;
use App\Models\Router;
use App\Models\RouterCommand;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherBatch;
use App\Support\ApiException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/** Génération, vente, révocation, expiration et synchronisation des vouchers. Contexte tenant requis (sauf expireDue). */
final class VoucherService
{
    public function __construct(
        private readonly ConnectorManager $connectors,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Génère un lot de vouchers : lignes GENERATED en base + commandes pour le routeur (profil d'abord, puis vouchers
     * par paquets). Un voucher n'est AVAILABLE/VIP qu'après confirmation du routeur.
     *
     * @param  array{label?: ?string, valid_until?: ?Carbon, code_length?: int}  $options
     */
    public function createBatch(Router $router, HotspotProfile $profile, int $quantity, array $options, ?User $actor): VoucherBatch
    {
        if (! $profile->is_active) {
            throw new ApiException('profile_inactive', 'Ce profil est désactivé.', 422);
        }

        $connector = $this->connectors->for($router); // refuse tôt si le routeur n'a pas de connecteur utilisable

        return DB::transaction(function () use ($router, $profile, $quantity, $options, $actor, $connector) {
            $batch = VoucherBatch::create([
                'tenant_id' => $router->tenant_id,
                'site_id' => $router->site_id,
                'router_id' => $router->getKey(),
                'profile_id' => $profile->getKey(),
                'created_by' => $actor?->getKey(),
                'label' => $options['label'] ?? null,
                'quantity' => $quantity,
                'valid_until' => $options['valid_until'] ?? null,
            ]);

            $codes = $this->uniqueCodes($router, $quantity, (int) ($options['code_length'] ?? 8));
            $now = now();
            $metadata = ! empty($options['label']) ? json_encode(['label' => $options['label']]) : null;
            $isVip = $profile->kind === 'VIP';

            $rows = [];
            foreach ($codes as $code) {
                $rows[] = [
                    'id' => (string) Str::uuid(),
                    'tenant_id' => $router->tenant_id,
                    'site_id' => $router->site_id,
                    'router_id' => $router->getKey(),
                    'batch_id' => $batch->getKey(),
                    'profile_id' => $profile->getKey(),
                    'code' => $code,
                    'status' => VoucherStatus::GENERATED,
                    'sync_status' => Voucher::SYNC_PENDING,
                    'price' => $profile->price,
                    'currency' => $profile->currency,
                    'duration_minutes' => $profile->duration_minutes,
                    'rate_limit' => $profile->rate_limit,
                    'device_limit' => $profile->device_limit,
                    'data_limit_mb' => $profile->data_limit_mb,
                    'is_vip' => $isVip,
                    'valid_until' => $options['valid_until'] ?? null,
                    'created_by' => $actor?->getKey(),
                    'metadata' => $metadata,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            foreach (array_chunk($rows, 50) as $chunk) {
                Voucher::insert($chunk);
            }

            // Le profil doit exister sur le routeur avant les vouchers : la file livre dans l'ordre de création.
            $connector->send($router, CommandType::SYNC_PROFILES, ['profiles' => [$profile->toAgentPayload()]], $actor?->getKey());
            $this->enqueueCreation($router, $profile, collect($rows)->map(fn ($r) => $r['id'])->all(), $actor?->getKey());

            return $batch;
        });
    }

    /**
     * Relance la synchronisation des vouchers restés GENERATED dont l'envoi a échoué (routeur resté hors ligne trop
     * longtemps, par exemple). Retourne le nombre de vouchers renvoyés.
     */
    public function resyncFailed(Router $router, ?User $actor): int
    {
        $vouchers = Voucher::where('router_id', $router->getKey())
            ->where('status', VoucherStatus::GENERATED)
            ->where('sync_status', Voucher::SYNC_FAILED)
            ->get();

        foreach ($vouchers->groupBy('profile_id') as $profileId => $group) {
            $profile = HotspotProfile::find($profileId);
            if (! $profile) {
                continue;
            }
            $connector = $this->connectors->for($router);
            $connector->send($router, CommandType::SYNC_PROFILES, ['profiles' => [$profile->toAgentPayload()]], $actor?->getKey());
            Voucher::whereIn('id', $group->pluck('id'))->update(['sync_status' => Voucher::SYNC_PENDING]);
            $this->enqueueCreation($router, $profile, $group->pluck('id')->all(), $actor?->getKey());
        }

        return $vouchers->count();
    }

    /**
     * Révocation immédiate : le voucher devient REVOKED en base et une commande retire l'utilisateur du HotSpot
     * et coupe sa session. Idempotent : révoquer un voucher déjà révoqué ne change rien.
     *
     * @return bool vrai si l'état a changé
     */
    public function revoke(Voucher $voucher, ?User $actor): bool
    {
        return DB::transaction(function () use ($voucher, $actor) {
            $fresh = Voucher::lockForUpdate()->findOrFail($voucher->getKey());

            if ($fresh->status === VoucherStatus::REVOKED) {
                return false;
            }

            if (! VoucherStatus::canTransition($fresh->status, VoucherStatus::REVOKED)) {
                throw new ApiException('voucher_not_revocable', 'Ce voucher ne peut plus être révoqué.', 409, ['status' => $fresh->status]);
            }

            $was = $fresh->status;
            $fresh->update(['status' => VoucherStatus::REVOKED, 'revoked_at' => now()]);
            $router = Router::findOrFail($fresh->router_id);

            $this->connectors->for($router)->send($router, CommandType::REVOKE_VOUCHER, [
                'voucher_id' => $fresh->getKey(),
                'code' => $fresh->code,
            ], $actor?->getKey());

            $this->audit->log('voucher.revoked', $actor, $fresh, ['code_suffix' => substr($fresh->code, -3), 'was' => $was]);

            return true;
        });
    }

    /** Marque un voucher vendu (utilisé par les ventes, Phase 4). AVAILABLE -> SOLD uniquement. */
    public function markSold(Voucher $voucher, ?User $seller): Voucher
    {
        return DB::transaction(function () use ($voucher, $seller) {
            $fresh = Voucher::lockForUpdate()->findOrFail($voucher->getKey());

            if (! VoucherStatus::canTransition($fresh->status, VoucherStatus::SOLD) || $fresh->status !== VoucherStatus::AVAILABLE) {
                throw new ApiException('voucher_not_sellable', 'Ce voucher n\'est pas disponible à la vente.', 409, ['status' => $fresh->status]);
            }

            $fresh->update(['status' => VoucherStatus::SOLD, 'sold_by' => $seller?->getKey(), 'sold_at' => now()]);

            return $fresh;
        });
    }

    /**
     * Effets du résultat d'une commande CREATE_VOUCHER. Contrat : en cas de succès l'agent liste les codes créés
     * (`created`) et ceux refusés (`failed`) ; un code qui n'est dans aucune liste est considéré non confirmé.
     */
    public function applyCreateResult(RouterCommand $command): void
    {
        $ids = (array) ($command->payload['voucher_ids'] ?? []);
        if ($ids === []) {
            return;
        }

        $created = $command->status === CommandStatus::SUCCEEDED
            ? array_values(array_filter((array) ($command->result['created'] ?? []), 'is_string'))
            : [];

        $base = fn () => Voucher::whereIn('id', $ids);

        if ($created !== []) {
            $base()->whereIn('code', $created)->update(['sync_status' => Voucher::SYNC_SYNCED]);
            $base()->whereIn('code', $created)->where('status', VoucherStatus::GENERATED)->where('is_vip', false)->update(['status' => VoucherStatus::AVAILABLE]);
            $base()->whereIn('code', $created)->where('status', VoucherStatus::GENERATED)->where('is_vip', true)->update(['status' => VoucherStatus::VIP]);
        }

        $unconfirmed = $base()->where('sync_status', '!=', Voucher::SYNC_SYNCED);
        $failedCount = (clone $unconfirmed)->count();
        if ($failedCount > 0) {
            $unconfirmed->update(['sync_status' => Voucher::SYNC_FAILED]);
            NetworkEvent::record($command->tenant_id, $command->router_id, 'VOUCHER_SYNC_FAILED', 'WARNING', null, [
                'count' => $failedCount,
                'command_id' => $command->getKey(),
                'error' => $command->error,
            ]);
        }
    }

    /**
     * Tâche planifiée (tous tenants) : les vouchers dont l'échéance absolue est passée deviennent EXPIRED et leur
     * utilisateur HotSpot est désactivé. Retourne le nombre de vouchers expirés.
     */
    public function expireDue(): int
    {
        $count = 0;

        Voucher::withoutGlobalScopes()
            ->whereNotNull('valid_until')->where('valid_until', '<', now())
            ->whereIn('status', VoucherStatus::LIVE)
            ->chunkById(200, function ($vouchers) use (&$count) {
                foreach ($vouchers as $voucher) {
                    $updated = Voucher::withoutGlobalScopes()->whereKey($voucher->getKey())
                        ->whereIn('status', VoucherStatus::LIVE)->update(['status' => VoucherStatus::EXPIRED]);
                    if ($updated === 0) {
                        continue;
                    }

                    $router = Router::withoutGlobalScopes()->find($voucher->router_id);
                    if ($router && $router->connection_mode === 'AGENT') {
                        app(CommandQueue::class)->enqueue($router, CommandType::DISABLE_VOUCHER, [
                            'voucher_id' => $voucher->getKey(),
                            'code' => $voucher->code,
                        ]);
                    }
                    $count++;
                }
            });

        return $count;
    }

    /** @param array<int, string> $voucherIds */
    private function enqueueCreation(Router $router, HotspotProfile $profile, array $voucherIds, ?string $actorId): void
    {
        $connector = $this->connectors->for($router);
        $profileName = $profile->routerName();

        foreach (array_chunk($voucherIds, (int) config('network.voucher_chunk')) as $chunk) {
            $vouchers = Voucher::whereIn('id', $chunk)->orderBy('id')->get();

            $connector->send($router, CommandType::CREATE_VOUCHER, [
                'voucher_ids' => $vouchers->pluck('id')->all(),
                'vouchers' => $vouchers->map(fn (Voucher $v) => $v->toAgentPayload($profileName))->all(),
            ], $actorId);
        }
    }

    /** @return array<int, string> */
    private function uniqueCodes(Router $router, int $quantity, int $length): array
    {
        $codes = [];

        for ($round = 0; $round < 6 && count($codes) < $quantity; $round++) {
            $candidates = [];
            while (count($codes) + count($candidates) < $quantity) {
                $candidates[RouterOsValue::randomCode($length)] = true;
            }
            $candidates = array_diff_key($candidates, $codes);

            $taken = Voucher::where('router_id', $router->getKey())->whereIn('code', array_keys($candidates))->pluck('code')->all();
            $codes += array_diff_key($candidates, array_flip($taken));
        }

        if (count($codes) < $quantity) {
            throw new RuntimeException('Impossible de générer assez de codes uniques.');
        }

        // Un code composé uniquement de chiffres devient une clé entière en PHP : on force la chaîne.
        return array_map('strval', array_keys($codes));
    }
}
