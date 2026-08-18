<?php

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryProductOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_set_a_category_grid_order_used_by_category_and_catalogue_pages(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $face = Category::query()->create(['name' => 'Visage', 'slug' => 'visage', 'is_active' => true, 'sort_order' => 0]);
        $body = Category::query()->create(['name' => 'Corps', 'slug' => 'corps', 'is_active' => true, 'sort_order' => 1]);
        $first = $this->product($face, 'Premier produit', 'premier-produit', 0);
        $second = $this->product($face, 'Second produit', 'second-produit', 1);
        $bodyProduct = $this->product($body, 'Produit corps', 'produit-corps', 0);

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/v1/admin/categories/'.$face->public_id.'/product-order', [
                'product_public_ids' => [$second->public_id, $first->public_id],
            ])
            ->assertOk();

        $this->assertDatabaseHas('products', ['id' => $second->id, 'sort_order' => 0]);
        $this->assertDatabaseHas('products', ['id' => $first->id, 'sort_order' => 1]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'catalog.category_products_reordered', 'auditable_id' => (string) $face->id]);

        $this->get('/categories/visage')
            ->assertOk()
            ->assertSeeInOrder(['Second produit', 'Premier produit']);
        $this->get('/produits')
            ->assertOk()
            ->assertSeeInOrder(['Second produit', 'Premier produit', $bodyProduct->name]);
        $this->get('/categories/visage?sort=name_asc')
            ->assertOk()
            ->assertSeeInOrder(['Premier produit', 'Second produit']);
        $this->get('/categories/visage?sort=unsupported')
            ->assertOk()
            ->assertSeeInOrder(['Second produit', 'Premier produit']);
    }

    public function test_product_order_requires_the_complete_current_category_list(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $category = Category::query()->create(['name' => 'Visage', 'slug' => 'visage', 'is_active' => true]);
        $first = $this->product($category, 'Premier produit', 'premier-produit', 0);
        $second = $this->product($category, 'Second produit', 'second-produit', 1);

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/v1/admin/categories/'.$category->public_id.'/product-order', [
                'product_public_ids' => [$second->public_id],
            ])
            ->assertStatus(422);

        $this->assertDatabaseHas('products', ['id' => $first->id, 'sort_order' => 0]);
        $this->assertDatabaseHas('products', ['id' => $second->id, 'sort_order' => 1]);
    }

    private function product(Category $category, string $name, string $slug, int $sortOrder): Product
    {
        return Product::query()->create([
            'category_id' => $category->id,
            'sort_order' => $sortOrder,
            'name' => $name,
            'slug' => $slug,
            'regular_price_millimes' => 20_000,
            'stock_quantity' => 4,
            'is_active' => true,
            'has_variants' => false,
            'published_at' => now(),
        ]);
    }
}
