<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_branding', function (Blueprint $table) {
            $table->string('portal_slug', 24)->nullable()->unique();   // identifiant PUBLIC du portail (aléatoire, non devinable)
            $table->boolean('portal_enabled')->default(false);
        });

        Schema::table('hotspot_profiles', function (Blueprint $table) {
            $table->boolean('portal_visible')->default(false);          // proposé à l'achat direct sur le portail
        });

        // Un paiement peut financer une facture d'abonnement OU une commande du portail.
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->uuid('portal_order_id')->nullable()->index();
            $table->uuid('invoice_id')->nullable()->change();
        });

        Schema::create('portal_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('router_id')->constrained('routers')->cascadeOnDelete();
            $table->foreignUuid('profile_id')->constrained('hotspot_profiles');
            $table->string('public_token', 40)->unique();               // secret du client : lecture du statut/code
            $table->string('client_ref', 64)->nullable();
            $table->unsignedBigInteger('amount');                        // instantané du prix
            $table->string('currency', 3);
            $table->string('locale', 5)->default('fr');
            $table->string('customer_phone', 20)->nullable();
            $table->string('status', 20);
            $table->foreignUuid('voucher_id')->nullable()->constrained('vouchers')->nullOnDelete();
            $table->foreignUuid('sale_id')->nullable()->constrained('sales')->nullOnDelete();
            $table->timestamp('fulfilled_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'client_ref']);
            $table->index(['tenant_id', 'status', 'created_at']);
        });

        Schema::create('external_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('kind', 12)->default('OTHER');                // STARLINK, OTHER
            $table->string('name', 80);
            $table->string('url', 300);                                  // https uniquement ; AUCUN identifiant ni mot de passe
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_links');
        Schema::dropIfExists('portal_orders');
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->dropColumn('portal_order_id');
        });
        Schema::table('hotspot_profiles', function (Blueprint $table) {
            $table->dropColumn('portal_visible');
        });
        Schema::table('tenant_branding', function (Blueprint $table) {
            $table->dropColumn(['portal_slug', 'portal_enabled']);
        });
    }
};
