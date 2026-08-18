<?php

namespace Database\Seeders;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use Illuminate\Database\Seeder;

class PassionCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['name' => 'Visage', 'slug' => 'visage', 'description' => 'Soins ciblés pour le visage.'],
            ['name' => 'Corps', 'slug' => 'corps', 'description' => 'Gestes doux pour le corps.'],
            ['name' => 'Cheveux', 'slug' => 'cheveux', 'description' => 'Rituels pour les cheveux.'],
            ['name' => 'Maquillage', 'slug' => 'maquillage', 'description' => 'Essentiels de maquillage.'],
            ['name' => 'Parfums', 'slug' => 'parfums', 'description' => 'Sillages et eaux parfumées.'],
            ['name' => 'Bien-être', 'slug' => 'bien-etre', 'description' => 'Moments de soin à la maison.'],
            ['name' => 'Coffrets', 'slug' => 'coffrets', 'description' => 'Sélections à offrir ou à découvrir.'],
        ];

        $categoryIds = [];
        foreach ($categories as $sortOrder => $category) {
            $model = Category::query()->updateOrCreate(
                ['slug' => $category['slug']],
                [...$category, 'is_active' => true, 'sort_order' => $sortOrder],
            );
            $categoryIds[$category['slug']] = $model->id;
        }

        $products = [
            ['category' => 'visage', 'name' => 'Sérum hydratant à l’acide hyaluronique', 'slug' => 'serum-hydratant-acide-hyaluronique', 'price' => 39_900, 'stock' => 24],
            ['category' => 'visage', 'name' => 'Crème éclat à la vitamine C', 'slug' => 'creme-eclat-vitamine-c', 'price' => 42_500, 'stock' => 18],
            ['category' => 'corps', 'name' => 'Huile sèche nourrissante', 'slug' => 'huile-seche-nourrissante', 'price' => 32_000, 'stock' => 30],
            ['category' => 'corps', 'name' => 'Lait corps au beurre de karité', 'slug' => 'lait-corps-beurre-karite', 'price' => 27_500, 'stock' => 22],
            ['category' => 'cheveux', 'name' => 'Masque réparateur à l’argan', 'slug' => 'masque-reparateur-argan', 'price' => 36_000, 'stock' => 16],
            ['category' => 'maquillage', 'name' => 'Rouge à lèvres velours Rose nude', 'slug' => 'rouge-levres-velours-rose-nude', 'price' => 24_900, 'stock' => 35],
            ['category' => 'maquillage', 'name' => 'Palette regard terre cuite', 'slug' => 'palette-regard-terre-cuite', 'price' => 49_000, 'stock' => 14],
            ['category' => 'parfums', 'name' => 'Brume parfumée Fleur de coton', 'slug' => 'brume-parfumee-fleur-coton', 'price' => 29_500, 'stock' => 28],
            ['category' => 'bien-etre', 'name' => 'Bougie rituel du soir', 'slug' => 'bougie-rituel-soir', 'price' => 31_000, 'stock' => 20],
            ['category' => 'coffrets', 'name' => 'Coffret découverte douceur', 'slug' => 'coffret-decouverte-douceur', 'price' => 79_000, 'stock' => 12],
        ];

        foreach ($products as $product) {
            Product::query()->updateOrCreate(
                ['slug' => $product['slug']],
                [
                    'category_id' => $categoryIds[$product['category']],
                    'name' => $product['name'],
                    'regular_price_millimes' => $product['price'],
                    'stock_quantity' => $product['stock'],
                    'low_stock_threshold' => 5,
                    'is_active' => true,
                    'has_variants' => false,
                    'published_at' => now(),
                ],
            );
        }
    }
}
