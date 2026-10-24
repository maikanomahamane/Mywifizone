<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 4 : ventes, caisse. Montants en entiers (unités mineures) + devise, jamais de décimaux. */
return new class extends Migration
{
    public function up(): void
    {
        // Fuseau du tenant : une vente appartient au « jour » local du propriétaire (rapports quotidiens).
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('timezone', 64)->default('Africa/Niamey');
        });

        Schema::create('cash_registers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->string('name', 120);
            $table->string('currency', 3);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'is_active']);
        });

        Schema::create('cash_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('cash_register_id')->constrained('cash_registers');
            $table->foreignUuid('opened_by')->constrained('users');
            $table->foreignUuid('closed_by')->nullable()->constrained('users');
            $table->string('status', 8)->default('OPEN');            // OPEN, CLOSED
            $table->string('currency', 3);
            $table->bigInteger('opening_balance');
            $table->bigInteger('expected_balance')->nullable();      // calculé à la clôture
            $table->bigInteger('counted_balance')->nullable();       // saisi à la clôture
            $table->bigInteger('difference')->nullable();            // compté - attendu
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->string('note', 255)->nullable();
            // = id de la caisse tant que la session est ouverte, NULL une fois close. UNIQUE => une seule session
            // ouverte par caisse, garanti par la base (index partiel portable PostgreSQL/SQLite, sans SQL spécifique).
            $table->uuid('open_key')->nullable()->unique();
            // Idem par utilisateur : un caissier n'a qu'une session ouverte à la fois.
            $table->uuid('open_user_key')->nullable()->unique();
            $table->timestamps();

            $table->index(['tenant_id', 'opened_at']);
        });

        Schema::create('sales', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->foreignUuid('router_id')->nullable()->constrained('routers')->nullOnDelete();
            $table->foreignUuid('voucher_id')->nullable()->unique()->constrained('vouchers')->nullOnDelete();
            $table->foreignUuid('profile_id')->nullable()->constrained('hotspot_profiles')->nullOnDelete();
            $table->foreignUuid('agent_id')->nullable()->constrained('users')->nullOnDelete();   // le vendeur
            $table->foreignUuid('cash_session_id')->nullable()->constrained('cash_sessions')->nullOnDelete();
            $table->bigInteger('amount');                                                        // instantané du prix
            $table->string('currency', 3);
            $table->string('payment_method', 16);                                                // CASH, MOBILE_MONEY, BANK, OTHER
            $table->string('provider_transaction_id', 100)->nullable();                          // référence saisie, NON vérifiée
            $table->string('client_ref', 64)->nullable();                                        // idempotence côté application
            $table->string('status', 12)->default('COMPLETED');                                  // COMPLETED, VOIDED
            $table->timestamp('sold_at');
            $table->date('sold_on');                                                             // jour local du tenant
            $table->timestamp('voided_at')->nullable();
            $table->foreignUuid('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason', 255)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'client_ref']);
            $table->unique(['tenant_id', 'payment_method', 'provider_transaction_id']);
            $table->index(['tenant_id', 'sold_on', 'currency']);
            $table->index(['tenant_id', 'agent_id', 'sold_on']);
            $table->index(['tenant_id', 'site_id', 'sold_on']);
            $table->index(['tenant_id', 'profile_id', 'sold_on']);
        });

        // Journal de caisse : append-only. `amount` est signé (+ entrée, - sortie).
        Schema::create('cash_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('cash_session_id')->constrained('cash_sessions');
            $table->foreignUuid('cash_register_id')->constrained('cash_registers');
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('sale_id')->nullable()->constrained('sales')->nullOnDelete();
            $table->string('type', 16);   // OPEN, CASH_IN, SALE, EXPENSE, WITHDRAWAL, ADJUSTMENT, CLOSE
            $table->bigInteger('amount');
            $table->string('currency', 3);
            $table->string('note', 255)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at', 6);

            $table->index(['cash_session_id', 'created_at']);
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_transactions');
        Schema::dropIfExists('sales');
        Schema::dropIfExists('cash_sessions');
        Schema::dropIfExists('cash_registers');
        Schema::table('tenants', fn (Blueprint $t) => $t->dropColumn('timezone'));
    }
};
