<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class CreateSuperAdmin extends Command
{
    protected $signature = 'admin:create-super {--name= : Full name} {--email= : Email address} {--password= : Password}';

    protected $description = 'Create or promote a local Super Admin account';

    public function handle(): int
    {
        $name = $this->option('name') ?: $this->ask('Nom complet');
        $email = $this->option('email') ?: $this->ask('Adresse e-mail');
        $password = $this->option('password') ?: $this->secret('Mot de passe (12 caractères minimum)');
        if (! is_string($name) || ! is_string($email) || ! is_string($password) || mb_strlen($password) < 12) {
            $this->error('Nom, e-mail et mot de passe de 12 caractères minimum requis.');

            return self::FAILURE;
        }
        $user = User::query()->firstOrNew(['email' => mb_strtolower(trim($email))]);
        $user->fill(['name' => trim($name), 'password' => Hash::make($password), 'role' => 'super_admin', 'is_active' => true]);
        $user->save();
        $this->info('Super Admin prêt : '.$user->email);

        return self::SUCCESS;
    }
}
