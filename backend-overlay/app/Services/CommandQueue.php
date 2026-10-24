<?php

namespace App\Services;

use App\Domain\Network\CommandStatus;
use App\Models\Router;
use App\Models\RouterCommand;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * File de commandes par routeur, avec bail (lease) : l'agent récupère des commandes, les exécute puis renvoie un
 * résultat. Sans résultat avant l'échéance du bail, la commande revient en file (au plus `max_attempts` livraisons).
 * Cette classe ne gère QUE les transitions d'état ; les effets métier sont appliqués par CommandResults.
 */
final class CommandQueue
{
    /** @param array<string, mixed> $payload */
    public function enqueue(Router $router, string $type, array $payload, ?string $createdBy = null): RouterCommand
    {
        return RouterCommand::create([
            'tenant_id' => $router->tenant_id,
            'router_id' => $router->getKey(),
            'type' => $type,
            'payload' => $payload,
            'status' => CommandStatus::PENDING,
            'max_attempts' => (int) config('network.command_max_attempts'),
            'available_at' => null, // disponible immédiatement (une date n'est posée que pour un retry avec délai)
            'created_by' => $createdBy,
        ]);
    }

    /** Une commande de ce type est-elle déjà en attente ou en cours pour ce routeur ? (évite d'empiler les relevés) */
    public function findOpen(Router $router, string $type): ?RouterCommand
    {
        return RouterCommand::where('router_id', $router->getKey())
            ->where('type', $type)
            ->whereIn('status', [CommandStatus::PENDING, CommandStatus::LEASED])
            ->orderBy('created_at')
            ->first();
    }

    /**
     * Remet à l'agent les prochaines commandes (ordre de création). Le verrou de ligne empêche deux interrogations
     * simultanées de livrer deux fois la même commande.
     *
     * @return Collection<int, RouterCommand>
     */
    public function lease(Router $router, int $limit): Collection
    {
        return DB::transaction(function () use ($router, $limit) {
            $now = now();

            $candidates = RouterCommand::where('router_id', $router->getKey())
                ->whereColumn('attempts', '<', 'max_attempts')
                ->where(function ($q) use ($now) {
                    $q->where(function ($q) use ($now) {
                        $q->where('status', CommandStatus::PENDING)
                            ->where(fn ($q) => $q->whereNull('available_at')->orWhere('available_at', '<=', $now));
                    })->orWhere(fn ($q) => $q->where('status', CommandStatus::LEASED)->where('leased_until', '<', $now));
                })
                ->orderBy('created_at')->orderBy('id')
                ->limit($limit)
                ->lockForUpdate()
                ->get();

            foreach ($candidates as $command) {
                $command->update([
                    'status' => CommandStatus::LEASED,
                    'attempts' => $command->attempts + 1,
                    'leased_until' => $now->copy()->addSeconds((int) config('network.lease_seconds')),
                ]);
            }

            return $candidates;
        });
    }

    /**
     * Enregistre un succès. Accepté tant que la commande n'est pas terminale (un résultat tardif après remise en file
     * reste valable : l'agent a bien exécuté). Retourne null si la commande était déjà terminale (rejeu ignoré).
     *
     * @param  array<string, mixed>  $result
     */
    public function complete(RouterCommand $command, array $result): ?RouterCommand
    {
        return DB::transaction(function () use ($command, $result) {
            $fresh = RouterCommand::lockForUpdate()->find($command->getKey());

            if (! $fresh || CommandStatus::isTerminal($fresh->status)) {
                return null;
            }

            $fresh->update([
                'status' => CommandStatus::SUCCEEDED,
                'result' => $result ?: null,
                'error' => null,
                'executed_at' => now(),
                'leased_until' => null,
            ]);

            return $fresh;
        });
    }

    /**
     * Enregistre un échec signalé par l'agent : nouvelle tentative avec délai tant qu'il en reste, sinon FAILED.
     * Retourne null si la commande était déjà terminale ; sinon la commande (vérifier son statut).
     */
    public function fail(RouterCommand $command, string $error, bool $retryable = true): ?RouterCommand
    {
        return DB::transaction(function () use ($command, $error, $retryable) {
            $fresh = RouterCommand::lockForUpdate()->find($command->getKey());

            if (! $fresh || CommandStatus::isTerminal($fresh->status)) {
                return null;
            }

            $error = mb_substr($error, 0, 1000);

            if ($retryable && $fresh->attempts < $fresh->max_attempts) {
                $fresh->update([
                    'status' => CommandStatus::PENDING,
                    'error' => $error,
                    'leased_until' => null,
                    'available_at' => now()->addSeconds($this->backoff($fresh->attempts)),
                ]);
            } else {
                $fresh->update(['status' => CommandStatus::FAILED, 'error' => $error, 'executed_at' => now(), 'leased_until' => null]);
            }

            return $fresh;
        });
    }

    /**
     * Ménage : baux expirés (remise en file ou échec si les tentatives sont épuisées) et commandes restées trop
     * longtemps en file. Retourne les commandes devenues FAILED (leurs effets métier restent à appliquer).
     *
     * @return Collection<int, RouterCommand>
     */
    public function reap(?Router $router = null): Collection
    {
        $failed = collect();
        $now = now();
        $scope = fn () => RouterCommand::withoutGlobalScopes()->when($router, fn ($q) => $q->where('router_id', $router->getKey()));

        $scope()->where('status', CommandStatus::LEASED)->where('leased_until', '<', $now)
            ->chunkById(200, function ($commands) use (&$failed, $now) {
                foreach ($commands as $command) {
                    if ($command->attempts >= $command->max_attempts) {
                        $command->update(['status' => CommandStatus::FAILED, 'error' => 'lease_expired', 'executed_at' => $now, 'leased_until' => null]);
                        $failed->push($command);
                    } else {
                        $command->update([
                            'status' => CommandStatus::PENDING,
                            'leased_until' => null,
                            'available_at' => $now->copy()->addSeconds($this->backoff($command->attempts)),
                        ]);
                    }
                }
            });

        $cutoff = $now->copy()->subMinutes((int) config('network.command_ttl_minutes'));
        $scope()->where('status', CommandStatus::PENDING)->where('created_at', '<', $cutoff)
            ->chunkById(200, function ($commands) use (&$failed, $now) {
                foreach ($commands as $command) {
                    $command->update(['status' => CommandStatus::FAILED, 'error' => 'expired_in_queue', 'executed_at' => $now]);
                    $failed->push($command);
                }
            });

        return $failed;
    }

    private function backoff(int $attempts): int
    {
        $delays = (array) config('network.retry_backoff_seconds');

        return (int) ($delays[min(max($attempts, 1), count($delays)) - 1] ?? 60);
    }
}
