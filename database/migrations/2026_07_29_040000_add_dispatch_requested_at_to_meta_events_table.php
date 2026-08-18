<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meta_events', function (Blueprint $table): void {
            $table->timestamp('dispatch_requested_at')->nullable()->after('next_retry_at');
            $table->index(['capi_state', 'dispatch_requested_at']);
        });
    }

    public function down(): void
    {
        Schema::table('meta_events', function (Blueprint $table): void {
            $table->dropIndex(['capi_state', 'dispatch_requested_at']);
            $table->dropColumn('dispatch_requested_at');
        });
    }
};
