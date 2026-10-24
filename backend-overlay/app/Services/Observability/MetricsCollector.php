<?php

namespace App\Services\Observability;

use App\Domain\Network\CommandStatus;
use App\Domain\Network\RouterStatus;
use App\Domain\Payments\PaymentStatus;
use App\Domain\Portal\PortalOrderStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Compteurs d'exploitation, tous tenants confondus (vue plateforme). Requêtes d'agrégat directes (DB::table) : le scope
 * multi-tenant est volontairement absent ici, et RIEN de ce qui sort n'identifie un tenant, un utilisateur ou un code.
 */
final class MetricsCollector
{
    /** @return array<string, int|float> */
    public function snapshot(): array
    {
        $stuckBefore = now()->subMinutes((int) config('payments.pending_timeout_minutes') + 10);
        $oldCommand = now()->subMinutes((int) config('monitoring.command_old_minutes'));

        return [
            'tenants_active' => DB::table('tenants')->where('status', 'ACTIVE')->count(),
            'tenants_suspended' => DB::table('tenants')->where('status', 'SUSPENDED')->count(),
            'routers_online' => DB::table('routers')->where('status', RouterStatus::ONLINE)->count(),
            'routers_offline' => DB::table('routers')->where('status', RouterStatus::OFFLINE)->count(),
            'routers_pending' => DB::table('routers')->where('status', RouterStatus::PENDING)->count(),
            'commands_pending' => DB::table('router_commands')->where('status', CommandStatus::PENDING)->count(),
            'commands_pending_old' => DB::table('router_commands')->where('status', CommandStatus::PENDING)->where('created_at', '<=', $oldCommand)->count(),
            'commands_failed_24h' => DB::table('router_commands')->where('status', CommandStatus::FAILED)->where('updated_at', '>=', now()->subDay())->count(),
            'payments_stuck' => DB::table('payment_transactions')->whereIn('status', [PaymentStatus::INITIATED, PaymentStatus::PENDING])->where('created_at', '<=', $stuckBefore)->count(),
            'portal_orders_needs_attention' => DB::table('portal_orders')->where('status', PortalOrderStatus::NEEDS_ATTENTION)->count(),
            'vouchers_sync_failed' => DB::table('vouchers')->where('sync_status', 'FAILED')->count(),
            'failed_jobs' => Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0,
            'queue_size' => $this->queueSize(),
        ];
    }

    /** @return array<string, array<string, int>> compteurs étiquetés */
    public function labelled(): array
    {
        $since = now()->subDay();
        $payments = DB::table('payment_transactions')->where('created_at', '>=', $since)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->all();
        $orders = DB::table('portal_orders')->where('created_at', '>=', $since)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->all();

        return ['payments_24h' => array_map('intval', $payments), 'portal_orders_24h' => array_map('intval', $orders)];
    }

    /** @return list<array{name: string, help: string, type?: string, samples: list<array{labels?: array<string, string>, value: int|float}>}> */
    public function prometheus(): array
    {
        $s = $this->snapshot();
        $l = $this->labelled();
        $gauge = fn (string $name, string $help, string $key) => ['name' => 'wifizone_'.$name, 'help' => $help, 'samples' => [['value' => $s[$key]]]];

        $metrics = [
            ['name' => 'wifizone_tenants', 'help' => 'Tenants par statut', 'samples' => [
                ['labels' => ['status' => 'active'], 'value' => $s['tenants_active']],
                ['labels' => ['status' => 'suspended'], 'value' => $s['tenants_suspended']],
            ]],
            ['name' => 'wifizone_routers', 'help' => 'Routeurs par statut', 'samples' => [
                ['labels' => ['status' => 'online'], 'value' => $s['routers_online']],
                ['labels' => ['status' => 'offline'], 'value' => $s['routers_offline']],
                ['labels' => ['status' => 'pending'], 'value' => $s['routers_pending']],
            ]],
            $gauge('router_commands_pending', 'Commandes en attente de livraison à un agent', 'commands_pending'),
            $gauge('router_commands_pending_old', 'Commandes en attente depuis trop longtemps (agent muet)', 'commands_pending_old'),
            $gauge('router_commands_failed_24h', 'Commandes échouées sur 24 h', 'commands_failed_24h'),
            $gauge('payments_stuck', 'Paiements INITIATED/PENDING au-delà du délai', 'payments_stuck'),
            $gauge('portal_orders_needs_attention', 'Commandes du portail payées sans ticket livré', 'portal_orders_needs_attention'),
            $gauge('vouchers_sync_failed', 'Vouchers dont la création sur le routeur a échoué', 'vouchers_sync_failed'),
            $gauge('failed_jobs', 'Tâches de file en échec', 'failed_jobs'),
            $gauge('queue_size', 'Tâches en attente dans la file', 'queue_size'),
        ];
        foreach (['payments_24h' => ['wifizone_payments_24h', 'Paiements créés sur 24 h par statut'], 'portal_orders_24h' => ['wifizone_portal_orders_24h', 'Commandes du portail sur 24 h par statut']] as $key => [$name, $help]) {
            $samples = [];
            foreach ($l[$key] as $status => $n) {
                $samples[] = ['labels' => ['status' => strtolower((string) $status)], 'value' => $n];
            }
            $metrics[] = ['name' => $name, 'help' => $help, 'samples' => $samples];
        }

        return $metrics;
    }

    private function queueSize(): int
    {
        try {
            return (int) Queue::size();
        } catch (Throwable) {
            return 0; // la file n'est pas joignable : la sonde /health/ready et les logs le montreront
        }
    }
}
