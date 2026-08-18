<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->timestamp('archived_at')->nullable()->index()->after('lock_version');
        });
    }

    public function down(): void
    {
        // Destructive on populated databases: restore this column with a forward
        // migration before rollback if archived history must be preserved.
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('archived_at'));
    }
};
