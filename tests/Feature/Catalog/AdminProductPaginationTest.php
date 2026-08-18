<?php

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminProductPaginationTest extends TestCase
{
    use RefreshDatabase;

    public function test_products_are_bounded_and_accessible_beyond_the_first_page(): void
    {
        $category = Category::query()->create([
            'name' => 'Visage',
            'slug' => 'visage',
            'is_active' => true,
        ]);
        foreach (range(1, 30) as $position) {
            Product::query()->create([
                'category_id' => $category->id,
                'name' => sprintf('Produit %02d', $position),
                'slug' => sprintf('produit-%02d', $position),
                'regular_price_millimes' => 10_000,
                'stock_quantity' => 4,
                'is_active' => true,
                'has_variants' => false,
                'published_at' => now(),
            ]);
        }
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $first = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/products?per_page=25&page=1&sort=name');
        $first->assertOk()
            ->assertJsonCount(25, 'data.data')
            ->assertJsonPath('data.current_page', 1)
            ->assertJsonPath('data.last_page', 2)
            ->assertJsonPath('data.total', 30);

        $second = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/products?per_page=25&page=2&sort=name');
        $second->assertOk()
            ->assertJsonCount(5, 'data.data')
            ->assertJsonPath('data.current_page', 2);

        $firstIds = collect($first->json('data.data'))->pluck('public_id');
        $secondIds = collect($second->json('data.data'))->pluck('public_id');
        $this->assertTrue($firstIds->intersect($secondIds)->isEmpty());
    }
}
