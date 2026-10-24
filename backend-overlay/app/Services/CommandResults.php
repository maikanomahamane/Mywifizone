<?php

namespace App\Services;

use App\Domain\Network\CommandStatus;
use App\Domain\Network\CommandType;
use App\Domain\Tenancy\TenantContext;
use App\Models\NetworkEvent;
use App\Models\Router;
use App\Models\RouterCommand;

/**
 * Applique les effets métier d'une commande devenue terminale (SUCCEEDED ou FAILED), dans le contexte de son tenant.
 * Séparé de CommandQueue (transitions d'état) pour éviter les dépendances circulaires avec VoucherService.
 */
final class CommandResults
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly RouterTelemetry $telemetry,
        private readonly VoucherService $vouchers,
    ) {}

    public function apply(RouterCommand $command): void
    {
        if (! CommandStatus::isTerminal($command->status)) {
            return;
        }

        $this->context->run($command->tenant_id, function () use ($command) {
            if ($command->status === CommandStatus::FAILED) {
                NetworkEvent::record($command->tenant_id, $command->router_id, 'COMMAND_FAILED', 'WARNING', null, [
                    'command_id' => $command->getKey(),
                    'type' => $command->type,
                    'error' => $command->error,
                ]);
            }

            switch ($command->type) {
                case CommandType::CREATE_VOUCHER:
                    $this->vouchers->applyCreateResult($command);
                    break;

                case CommandType::FETCH_SESSIONS:
                    if ($command->status === CommandStatus::SUCCEEDED && ($router = Router::find($command->router_id))) {
                        $this->telemetry->ingestSessions(
                            $router,
                            (array) ($command->result['sessions'] ?? []),
                            (array) ($command->result['finished_codes'] ?? []),
                        );
                    }
                    break;

                case CommandType::FETCH_HEALTH:
                    if ($command->status === CommandStatus::SUCCEEDED && ($router = Router::find($command->router_id))) {
                        $this->telemetry->ingestHealth($router, (array) ($command->result['health'] ?? []));
                    }
                    break;

                default:
                    // SYNC_PROFILES, REVOKE_VOUCHER, DISABLE_VOUCHER, DISCONNECT_USER : pas d'effet local à appliquer.
                    break;
            }
        });
    }
}
