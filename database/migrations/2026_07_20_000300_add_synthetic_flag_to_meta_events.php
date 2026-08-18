<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meta_events', function (Blueprint $table): void {
            $table->boolean('is_synthetic')->default(false)->after('marketing_consent');
            $table->index(['is_synthetic', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('meta_events', function (Blueprint $table): void {
            $table->dropIndex(['is_synthetic', 'created_at']);
            $table->dropColumn('is_synthetic');
        });
    }
};
