<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->string('meta_catalog_id', 120)->nullable()->unique()->after('slug');
        });

        Schema::table('product_variants', function (Blueprint $table): void {
            $table->string('meta_catalog_id', 120)->nullable()->unique()->after('public_id');
        });
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table): void {
            $table->dropUnique(['meta_catalog_id']);
            $table->dropColumn('meta_catalog_id');
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->dropUnique(['meta_catalog_id']);
            $table->dropColumn('meta_catalog_id');
        });
    }
};
