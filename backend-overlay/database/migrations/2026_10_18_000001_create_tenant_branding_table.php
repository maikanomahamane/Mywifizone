<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 5 : personnalisation des documents imprimés (logo, nom, téléphone, couleur, slogan, conditions). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_branding', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->unique()->constrained('tenants')->cascadeOnDelete();
            $table->string('display_name', 80)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('slogan', 120)->nullable();
            $table->text('terms')->nullable();                 // borné à 600 caractères par la validation
            $table->string('primary_color', 7)->nullable();    // #RRGGBB
            $table->string('portal_url', 200)->nullable();     // URL de connexion du HotSpot, utilisée seulement comme texte du QR
            $table->string('logo_path', 160)->nullable();      // disque privé, nom aléatoire
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_branding');
    }
};
