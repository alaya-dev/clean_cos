<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table): void {
            $table->foreignId('parent_id')->nullable()->after('id')->constrained('categories')->nullOnDelete();
            $table->index(['parent_id', 'is_active', 'sort_order']);
        });

        DB::transaction(function (): void {
            foreach (DB::table('categories')->whereNull('deleted_at')->orderBy('id')->get() as $category) {
                if (! DB::table('products')->where('category_id', $category->id)->whereNull('deleted_at')->exists()) {
                    continue;
                }

                $baseSlug = $category->slug.'-produits';
                $slug = $baseSlug;
                $suffix = 2;
                while (DB::table('categories')->where('slug', $slug)->exists()) {
                    $slug = $baseSlug.'-'.$suffix++;
                }

                $subcategoryId = DB::table('categories')->insertGetId([
                    'parent_id' => $category->id,
                    'public_id' => (string) Str::ulid(),
                    'name' => 'Tous les produits',
                    'slug' => $slug,
                    'description' => $category->description,
                    'image_path' => $category->image_path,
                    'image_processing_status' => $category->image_processing_status,
                    'image_width' => $category->image_width,
                    'image_height' => $category->image_height,
                    'is_active' => $category->is_active,
                    'sort_order' => 0,
                    'seo_title' => $category->seo_title,
                    'seo_description' => $category->seo_description,
                    'created_at' => $category->created_at,
                    'updated_at' => $category->updated_at,
                ]);

                DB::table('products')->where('category_id', $category->id)->update(['category_id' => $subcategoryId]);
            }
        });
    }

    public function down(): void
{
    DB::transaction(function (): void {
        foreach (
            DB::table('categories')
                ->whereNotNull('parent_id')
                ->orderBy('id')
                ->get() as $subcategory
        ) {
            DB::table('products')
                ->where('category_id', $subcategory->id)
                ->update([
                    'category_id' => $subcategory->parent_id,
                ]);
        }

        DB::table('categories')
            ->whereNotNull('parent_id')
            ->delete();
    });

    // 1. Supprimer d'abord la contrainte FK
    Schema::table('categories', function (Blueprint $table): void {
        $table->dropForeign(['parent_id']);
    });

    // 2. Ensuite supprimer l'index composite
    Schema::table('categories', function (Blueprint $table): void {
        $table->dropIndex(['parent_id', 'is_active', 'sort_order']);
    });

    // 3. Enfin supprimer la colonne
    Schema::table('categories', function (Blueprint $table): void {
        $table->dropColumn('parent_id');
    });
}
};
