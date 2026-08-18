<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->unsignedInteger('sort_order')->default(0)->after('category_id');
            $table->index(['category_id', 'is_active', 'sort_order'], 'products_category_active_sort_order_index');
        });

        $positions = [];
        foreach (DB::table('products')->whereNull('deleted_at')->orderBy('category_id')->orderByDesc('published_at')->orderByDesc('id')->cursor() as $product) {
            $positions[$product->category_id] = ($positions[$product->category_id] ?? 0) + 1;
            DB::table('products')->where('id', $product->id)->update(['sort_order' => $positions[$product->category_id] - 1]);
        }
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex('products_category_active_sort_order_index');
            $table->dropColumn('sort_order');
        });
    }
};
