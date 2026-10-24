<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;

class CreateSuperAdmin extends Command
{
    protected $signature = 'wifizone:create-super-admin {email} {--name=Super Admin}';

    protected $description = 'Crée un utilisateur SUPER_ADMIN (hors tenant). Le mot de passe est demandé de façon interactive.';

    public function handle(): int
    {
        $role = Role::where('code', Role::SUPER_ADMIN)->first();
        if (! $role) {
            $this->error('Rôles absents : lancez d\'abord php artisan db:seed.');

            return self::FAILURE;
        }

        $email = mb_strtolower($this->argument('email'));
        if (User::where('email', $email)->exists()) {
            $this->error('Cet email existe déjà.');

            return self::FAILURE;
        }

        $password = $this->secret('Mot de passe (12 caractères minimum)');
        if (! is_string($password) || mb_strlen($password) < 12) {
            $this->error('Mot de passe trop court.');

            return self::FAILURE;
        }

        $user = new User(['name' => $this->option('name'), 'email' => $email, 'password' => $password]);
        $user->role_id = $role->getKey();
        $user->save();

        $this->info('Super admin créé.');

        return self::SUCCESS;
    }
}
