<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('force_password_change')->default(false)->after('is_active');
            $table->unsignedInteger('auth_version')->default(1)->after('force_password_change');
            $table->timestamp('last_login_at')->nullable()->after('auth_version');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['force_password_change', 'auth_version', 'last_login_at']);
        });
    }
};
