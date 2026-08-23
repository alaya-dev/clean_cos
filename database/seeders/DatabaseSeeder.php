<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::query()->firstOrCreate(
            ['email' => 'test@example.com'],
            ['name' => 'Test User', 'password' => Hash::make('password')],
        );

        $testSuperAdminPassword = config('app.test_super_admin_password');
        if (is_string($testSuperAdminPassword) && mb_strlen($testSuperAdminPassword) >= 12) {
            User::query()->updateOrCreate(
                ['email' => 'superadmin.test@cleancos.test'],
                [
                    'name' => 'Super Admin Test',
                    'password' => Hash::make('password'),
                    'role' => 'super_admin',
                    'is_active' => true,
                    'force_password_change' => true,
                    'auth_version' => 1,
                ],
            );
        }

        User::query()->updateOrCreate(
            ['email' => '3leya21@gmail.com'],
            [
                'name' => 'Super Admin Test',
                'password' => Hash::make('12345678'),
                'role' => 'super_admin',
                'is_active' => true,
                'force_password_change' => false,
                'auth_version' => 1,
            ],
        );

        $this->call(DemoPlatformSeeder::class);
    }
}
