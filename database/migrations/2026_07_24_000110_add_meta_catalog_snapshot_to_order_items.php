<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->string('meta_catalog_id_snapshot', 120)->nullable()->after('product_name_snapshot')->index();
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropIndex(['meta_catalog_id_snapshot']);
            $table->dropColumn('meta_catalog_id_snapshot');
        });
    }
};
