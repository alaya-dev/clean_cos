<?php

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use Database\Seeders\PassionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PassionCatalogSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_the_requested_catalogue_without_product_images_and_is_idempotent(): void
    {
        $this->seed(PassionCatalogSeeder::class);
        $this->seed(PassionCatalogSeeder::class);

        $this->assertSame(7, Category::query()->count());
        $this->assertSame(10, Product::query()->count());
        $this->assertSame(0, Product::query()->withCount('images')->having('images_count', '>', 0)->count());
        $this->assertDatabaseHas('categories', ['slug' => 'visage', 'image_path' => null, 'is_active' => true]);
        $this->assertDatabaseHas('products', ['slug' => 'serum-hydratant-acide-hyaluronique', 'is_active' => true]);
    }
}
