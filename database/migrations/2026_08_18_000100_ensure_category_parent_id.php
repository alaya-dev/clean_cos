<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('categories', 'parent_id')) {
            Schema::table('categories', function (Blueprint $table): void {
                $table->foreignId('parent_id')
                    ->nullable()
                    ->after('id')
                    ->constrained('categories')
                    ->nullOnDelete();
            });
        }

        if (! $this->hasParentOrderingIndex()) {
            Schema::table('categories', function (Blueprint $table): void {
                $table->index(
                    ['parent_id', 'is_active', 'sort_order'],
                    'categories_parent_active_sort_order_index'
                );
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('categories', 'parent_id')) {
            return;
        }

        if ($this->hasNamedIndex('categories_parent_active_sort_order_index')) {
            Schema::table('categories', function (Blueprint $table): void {
                $table->dropIndex('categories_parent_active_sort_order_index');
            });
        }

        Schema::table('categories', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('parent_id');
        });
    }

    private function hasParentOrderingIndex(): bool
    {
        foreach (Schema::getIndexes('categories') as $index) {
            if (
                $index['name'] === 'categories_parent_active_sort_order_index'
                || $index['columns'] === ['parent_id', 'is_active', 'sort_order']
            ) {
                return true;
            }
        }

        return false;
    }

    private function hasNamedIndex(string $name): bool
    {
        foreach (Schema::getIndexes('categories') as $index) {
            if ($index['name'] === $name) {
                return true;
            }
        }

        return false;
    }
};