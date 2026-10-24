<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Sonde « paiements coincés » et tâche payments:expire-stale : filtrent par statut puis par ancienneté.
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->index(['status', 'created_at'], 'payment_tx_status_created_idx');
        });
        // Sonde « vouchers dont la synchronisation a échoué » (compte global).
        Schema::table('vouchers', function (Blueprint $table) {
            $table->index('sync_status', 'vouchers_sync_status_idx');
        });
        // Sonde « commandes de routeur en attente depuis trop longtemps ».
        Schema::table('router_commands', function (Blueprint $table) {
            $table->index(['status', 'created_at'], 'router_cmd_status_created_idx');
        });
    }

    public function down(): void
    {
        Schema::table('router_commands', fn (Blueprint $t) => $t->dropIndex('router_cmd_status_created_idx'));
        Schema::table('vouchers', fn (Blueprint $t) => $t->dropIndex('vouchers_sync_status_idx'));
        Schema::table('payment_transactions', fn (Blueprint $t) => $t->dropIndex('payment_tx_status_created_idx'));
    }
};
