<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('public_id', 26)->nullable()->unique()->after('id');
            $table->string('role', 32)->default('admin')->after('password');
            $table->boolean('is_active')->default(true)->after('role');
            $table->timestamp('disabled_at')->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['public_id']);
            $table->dropColumn(['public_id', 'role', 'is_active', 'disabled_at']);
        });
    }
};
