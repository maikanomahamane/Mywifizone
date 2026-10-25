<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mode « connexion directe » : le serveur appelle l'API REST de RouterOS (HTTPS). On ne stocke JAMAIS le compte admin saisi
 * par le propriétaire : seul le compte dédié `wifizone` créé par l'application l'est, et son mot de passe est chiffré.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('routers', function (Blueprint $table) {
            $table->string('rest_host', 253)->nullable();
            $table->unsignedSmallInteger('rest_port')->nullable();
            $table->boolean('rest_verify_tls')->default(false);
            $table->string('rest_username', 64)->nullable();
            $table->text('rest_password')->nullable();          // chiffré (cast `encrypted`)
            $table->string('rest_error', 255)->nullable();      // dernier échec de connexion (diagnostic, sans secret)
            $table->timestamp('rest_checked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('routers', function (Blueprint $table) {
            $table->dropColumn(['rest_host', 'rest_port', 'rest_verify_tls', 'rest_username', 'rest_password', 'rest_error', 'rest_checked_at']);
        });
    }
};
