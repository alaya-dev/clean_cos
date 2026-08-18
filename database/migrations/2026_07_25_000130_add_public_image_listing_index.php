<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_images', function (Blueprint $table): void {
            $table->index(
                ['product_id', 'processing_status', 'is_primary', 'sort_order'],
                'product_images_public_listing_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('product_images', function (Blueprint $table): void {
            $table->dropIndex('product_images_public_listing_idx');
        });
    }
};
