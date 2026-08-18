<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('product_option_groups', 'is_current')) {
            Schema::table('product_option_groups', function (Blueprint $table): void {
                $table->boolean('is_current')->default(true)->after('sort_order');
            });
        }
        if (! Schema::hasIndex('product_option_groups', 'product_option_groups_product_id_index')) {
            Schema::table('product_option_groups', function (Blueprint $table): void {
                $table->index('product_id');
            });
        }
        if (Schema::hasIndex('product_option_groups', 'product_option_groups_product_id_name_unique')) {
            Schema::table('product_option_groups', function (Blueprint $table): void {
                $table->dropUnique(['product_id', 'name']);
            });
        }
        if (! Schema::hasIndex('product_option_groups', 'product_option_groups_product_id_is_current_sort_order_index')) {
            Schema::table('product_option_groups', function (Blueprint $table): void {
                $table->index(['product_id', 'is_current', 'sort_order']);
            });
        }

        if (! Schema::hasColumn('product_variants', 'is_current')) {
            Schema::table('product_variants', function (Blueprint $table): void {
                $table->boolean('is_current')->default(true)->after('is_default');
            });
        }
        if (Schema::hasIndex('product_variants', 'product_variants_product_id_combination_key_unique')) {
            Schema::table('product_variants', function (Blueprint $table): void {
                $table->dropUnique(['product_id', 'combination_key']);
            });
        }
        if (! Schema::hasIndex('product_variants', 'product_variants_product_id_combination_key_is_current_unique')) {
            Schema::table('product_variants', function (Blueprint $table): void {
                $table->unique(['product_id', 'combination_key', 'is_current']);
            });
        }
        if (! Schema::hasIndex('product_variants', 'product_variants_product_id_is_current_is_active_index')) {
            Schema::table('product_variants', function (Blueprint $table): void {
                $table->index(['product_id', 'is_current', 'is_active']);
            });
        }
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table): void {
            $table->dropIndex(['product_id', 'is_current', 'is_active']);
            $table->dropUnique(['product_id', 'combination_key', 'is_current']);
            $table->unique(['product_id', 'combination_key']);
            $table->dropColumn('is_current');
        });

        Schema::table('product_option_groups', function (Blueprint $table): void {
            $table->dropIndex(['product_id', 'is_current', 'sort_order']);
            $table->unique(['product_id', 'name']);
            $table->dropColumn('is_current');
        });
    }
};
