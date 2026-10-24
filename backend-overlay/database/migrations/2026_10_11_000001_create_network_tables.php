<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Routeur MikroTik. Les secrets d'accès au routeur ne sont JAMAIS stockés ici : en mode AGENT, l'agent
        // tourne côté client et garde les identifiants RouterOS ; le cloud ne connaît qu'un jeton d'agent (haché).
        Schema::create('routers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('model', 80)->nullable();
            $table->string('routeros_version', 40)->nullable();
            $table->string('connection_mode', 12)->default('AGENT');   // AGENT (sortant) ; REST réservé (non branché)
            $table->string('status', 10)->default('PENDING');          // PENDING, ONLINE, OFFLINE
            $table->string('hotspot_server', 60)->default('hotspot1');
            $table->string('enrollment_hash', 64)->nullable()->unique();   // sha256 du code d'enrôlement (usage unique)
            $table->timestamp('enrollment_expires_at')->nullable();
            $table->string('agent_token_hash', 64)->nullable()->unique();  // sha256 du jeton d'agent
            $table->timestamp('agent_enrolled_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->json('health')->nullable();                        // cpu, mémoire, uptime, version
            $table->unsignedInteger('clients_count')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'site_id']);
            $table->index(['status', 'last_seen_at']);
        });

        Schema::create('access_points', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('router_id')->constrained('routers')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('model', 80)->nullable();
            $table->string('location', 160)->nullable();
            $table->string('status', 10)->default('UNKNOWN');        // supervision réelle : Phase 8
            $table->unsignedInteger('clients_count')->default(0);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'router_id']);
        });

        // Un seul modèle de profil pour STANDARD et VIP (kind) : évite de dupliquer durée/débit/appareils.
        Schema::create('hotspot_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('kind', 10)->default('STANDARD');
            $table->unsignedBigInteger('price')->default(0);            // unités mineures ; 0 = gratuit
            $table->string('currency', 3);
            $table->unsignedInteger('duration_minutes')->nullable();     // temps de connexion cumulé ; null = illimité (VIP)
            $table->string('rate_limit', 24)->nullable();                // « 2M/2M » ; null = non limité
            $table->unsignedSmallInteger('device_limit')->default(1);
            $table->unsignedInteger('data_limit_mb')->nullable();        // null = illimité
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'kind', 'is_active']);
        });

        Schema::create('voucher_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('site_id')->constrained('sites')->cascadeOnDelete();
            $table->foreignUuid('router_id')->constrained('routers')->cascadeOnDelete();
            $table->foreignUuid('profile_id')->constrained('hotspot_profiles');
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('label', 80)->nullable();
            $table->unsignedInteger('quantity');
            $table->timestamp('valid_until')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
        });

        Schema::create('vouchers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('site_id')->constrained('sites')->cascadeOnDelete();
            $table->foreignUuid('router_id')->constrained('routers')->cascadeOnDelete();
            $table->foreignUuid('batch_id')->nullable()->constrained('voucher_batches')->nullOnDelete();
            $table->foreignUuid('profile_id')->constrained('hotspot_profiles');
            $table->string('code', 12);
            $table->string('status', 10);
            $table->string('sync_status', 8)->default('PENDING');       // PENDING, SYNCED, FAILED
            // Instantané du profil au moment de la génération : modifier le profil ne change pas les vouchers existants.
            $table->unsignedBigInteger('price')->default(0);
            $table->string('currency', 3);
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->string('rate_limit', 24)->nullable();
            $table->unsignedSmallInteger('device_limit')->default(1);
            $table->unsignedInteger('data_limit_mb')->nullable();
            $table->boolean('is_vip')->default(false);
            $table->timestamp('valid_until')->nullable();               // échéance absolue choisie par le propriétaire
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('sold_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sold_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->json('metadata')->nullable();                      // étiquette VIP, notes...
            $table->timestamps();

            $table->unique(['router_id', 'code']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'batch_id']);
            $table->index(['status', 'valid_until']);
        });

        Schema::create('router_commands', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('router_id')->constrained('routers')->cascadeOnDelete();
            $table->string('type', 24);
            $table->json('payload');
            $table->string('status', 10)->default('PENDING');
            $table->unsignedSmallInteger('attempts')->default(0);       // nombre de livraisons à l'agent
            $table->unsignedSmallInteger('max_attempts')->default(3);
            $table->timestamp('available_at')->nullable();               // pas avant (retry avec délai)
            $table->timestamp('leased_until')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->json('result')->nullable();
            $table->text('error')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps(6);

            $table->index(['router_id', 'status', 'available_at']);
            $table->index(['tenant_id', 'created_at']);
        });

        // Sessions HotSpot observées (nom distinct de la table `sessions` de Laravel).
        Schema::create('hotspot_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('router_id')->constrained('routers')->cascadeOnDelete();
            $table->string('remote_id', 40);                              // identifiant RouterOS de la session
            $table->string('username', 64);                               // code du voucher
            $table->string('ip', 45)->nullable();
            $table->string('mac', 17)->nullable();
            $table->unsignedBigInteger('uptime_seconds')->default(0);
            $table->unsignedBigInteger('bytes_in')->default(0);
            $table->unsignedBigInteger('bytes_out')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('last_seen_at');
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->unique(['router_id', 'remote_id']);
            $table->index(['tenant_id', 'ended_at']);
            $table->index(['router_id', 'ended_at']);
        });

        // Journal d'événements réseau (append-only), distinct de l'audit des actions utilisateur.
        Schema::create('network_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('router_id')->nullable()->constrained('routers')->cascadeOnDelete();
            $table->string('type', 32);
            $table->string('severity', 8)->default('INFO');               // INFO, WARNING, ERROR
            $table->string('message', 255)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['router_id', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach (['network_events', 'hotspot_sessions', 'router_commands', 'vouchers', 'voucher_batches', 'hotspot_profiles', 'access_points', 'routers'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
