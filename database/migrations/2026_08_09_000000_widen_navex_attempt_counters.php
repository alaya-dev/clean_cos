<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('navex_shipments', function (Blueprint $table): void {
            $table->unsignedInteger('attempt_count')->default(0)->change();
        });

        Schema::table('navex_shipment_attempts', function (Blueprint $table): void {
            $table->unsignedInteger('attempt_number')->change();
        });
    }

    public function down(): void
    {
        // Counters may already contain values above 255 after this migration.
        // Keeping the wider type is the only safe rollback for existing data.
    }
};
