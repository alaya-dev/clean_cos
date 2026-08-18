<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('complaints', function (Blueprint $table): void {
            $table->softDeletes();
            $table->index(['deleted_at', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('complaints', function (Blueprint $table): void {
            $table->dropIndex('complaints_deleted_at_status_index');
            $table->dropSoftDeletes();
        });
    }
};
