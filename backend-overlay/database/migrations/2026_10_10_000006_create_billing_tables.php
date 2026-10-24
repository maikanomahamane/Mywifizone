<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Montants : entiers en unités mineures (jamais de float) + code devise.
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 32)->unique();           // STARTER, PRO, BUSINESS
            $table->string('name');
            $table->unsignedBigInteger('monthly_price');
            $table->unsignedBigInteger('yearly_price')->nullable(); // null = formule annuelle non proposée
            $table->string('currency', 3)->default('XOF');
            $table->unsignedSmallInteger('trial_days')->nullable(); // null = valeur par défaut (config)
            $table->unsignedSmallInteger('grace_days')->nullable(); // null = valeur par défaut (config)
            $table->json('limits');                          // max_sites, max_routers, ... (null = illimité)
            $table->json('features');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('promotions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 40)->unique();
            $table->string('type', 20);                      // PERCENTAGE, FIXED, TRIAL_EXTENSION
            $table->unsignedInteger('value');                // %, unités mineures ou jours selon le type
            $table->string('currency', 3)->nullable();       // requis pour FIXED
            $table->string('plan_code', 32)->nullable();     // null = tous les plans
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->unsignedInteger('max_redemptions')->nullable();
            $table->unsignedInteger('redemptions_count')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Une seule ligne par tenant : l'historique est porté par les factures et l'audit.
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->unique()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('plan_id')->constrained('subscription_plans');
            $table->string('status', 16);
            $table->string('billing_cycle', 8)->nullable();  // MONTHLY | YEARLY (null pendant l'essai)
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->timestamp('grace_ends_at')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'current_period_end']);
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('subscription_id')->constrained('subscriptions');
            $table->foreignUuid('plan_id')->constrained('subscription_plans');
            $table->foreignUuid('promotion_id')->nullable()->constrained('promotions');
            $table->string('number', 40)->unique();
            $table->string('status', 16);                    // OPEN, PAID, VOID
            $table->string('billing_cycle', 8);
            $table->string('currency', 3);
            $table->unsignedBigInteger('subtotal');
            $table->unsignedBigInteger('discount')->default(0);
            $table->unsignedBigInteger('total');
            $table->timestamp('period_start')->nullable();   // fixés au paiement
            $table->timestamp('period_end')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('receipt_number', 40)->nullable()->unique();
            $table->uuid('payment_transaction_id')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('promotions');
        Schema::dropIfExists('subscription_plans');
    }
};
