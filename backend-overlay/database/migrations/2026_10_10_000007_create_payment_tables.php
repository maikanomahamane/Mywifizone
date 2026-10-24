<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_providers', function (Blueprint $table) {
            $table->string('code', 32)->primary();           // mock, wave, nita, airtel, card
            $table->string('name');
            $table->boolean('enabled')->default(false);      // activé par le Super Admin
            $table->json('countries')->nullable();           // null = non restreint (à renseigner selon les contrats)
            $table->json('currencies')->nullable();          // null = non restreint
            $table->timestamps();
        });

        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('invoice_id')->constrained('invoices');
            $table->string('provider', 32);
            $table->string('provider_reference')->nullable();
            $table->string('idempotency_key', 100);
            $table->unsignedBigInteger('amount');
            $table->string('currency', 3);
            $table->string('status', 16);
            $table->json('instructions')->nullable();
            $table->string('failure_reason')->nullable();
            $table->string('customer_phone', 20)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'idempotency_key']);
            $table->unique(['provider', 'provider_reference']);
            $table->index(['invoice_id', 'status']);
        });

        // Journal des webhooks reçus (après vérification de signature) : garantit l'idempotence.
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('provider', 32);
            $table->string('event_id', 120);
            $table->text('payload');
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->string('outcome', 32)->nullable();       // processed, duplicate, rejected, ignored

            $table->unique(['provider', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
        Schema::dropIfExists('payment_transactions');
        Schema::dropIfExists('payment_providers');
    }
};
