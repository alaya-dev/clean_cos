<?php

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryHierarchyTest extends TestCase
{
    use RefreshDatabase;

    public function test_parent_category_exposes_only_its_active_subcategories_before_products(): void
    {
        $parent = Category::query()->create(['name' => 'Visage', 'slug' => 'visage', 'is_active' => true]);
        $subcategory = Category::query()->create(['parent_id' => $parent->id, 'name' => 'Sérums', 'slug' => 'serums', 'is_active' => true]);
        Product::query()->create(['category_id' => $subcategory->id, 'name' => 'Sérum éclat', 'slug' => 'serum-eclat', 'regular_price_millimes' => 20_000, 'stock_quantity' => 4, 'is_active' => true, 'has_variants' => false, 'published_at' => now()]);

        $this->get('/categories/visage')->assertOk()->assertSee('Sérums')->assertDontSee('Sérum éclat');
        $this->get('/categories/serums')->assertOk()->assertSee('Sérum éclat');
    }

    public function test_admin_can_create_a_subcategory_and_category_api_returns_the_hierarchy(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $parent = Category::query()->create(['name' => 'Visage', 'slug' => 'visage', 'is_active' => true]);

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/categories', ['parent_public_id' => $parent->public_id, 'name' => 'Sérums', 'slug' => 'serums', 'is_active' => true, 'sort_order' => 0])->assertCreated();
        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/categories')->assertOk()->assertJsonPath('data.data.0.subcategories.0.name', 'Sérums');
    }
}
