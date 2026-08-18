<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_option_values', function (Blueprint $table): void {
            $table->foreignId('parent_product_option_value_id')
                ->nullable()
                ->after('product_option_group_id')
                ->constrained('product_option_values')
                ->nullOnDelete();
            $table->index('parent_product_option_value_id');
        });

        Schema::table('product_variants', function (Blueprint $table): void {
            $table->unsignedBigInteger('regular_price_millimes')->nullable()->after('combination_key');
            $table->unsignedBigInteger('promotional_price_millimes')->nullable()->after('regular_price_millimes');
            $table->boolean('is_default')->default(false)->after('is_active');
            $table->index(['product_id', 'is_default']);
        });
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table): void {
            $table->dropIndex(['product_id', 'is_default']);
            $table->dropColumn(['regular_price_millimes', 'promotional_price_millimes', 'is_default']);
        });

        Schema::table('product_option_values', function (Blueprint $table): void {
            $table->dropForeign(['parent_product_option_value_id']);
            $table->dropIndex(['parent_product_option_value_id']);
            $table->dropColumn('parent_product_option_value_id');
        });
    }
};
