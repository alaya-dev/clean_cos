<?php

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Actions\CreateProductAction;
use App\Domain\Catalog\Actions\ReplaceProductVariantsAction;
use App\Domain\Catalog\Actions\SwitchProductVariantModeAction;
use App\Domain\Catalog\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CreateProductActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_simple_product_with_product_level_stock(): void
    {
        $category = Category::query()->create(['name' => 'Visage', 'slug' => 'visage', 'is_active' => true, 'sort_order' => 0]);
        $product = app(CreateProductAction::class)->handle(['category_public_id' => $category->public_id, 'name' => 'Crème', 'slug' => 'creme', 'regular_price_millimes' => 10000, 'stock_quantity' => 5, 'is_active' => true, 'has_variants' => false]);
        $this->assertSame(5, $product->stock_quantity);
        $this->assertFalse($product->has_variants);
    }

    public function test_it_rejects_a_promotional_price_that_is_not_lower(): void
    {
        $category = Category::query()->create(['name' => 'Visage', 'slug' => 'visage', 'is_active' => true, 'sort_order' => 0]);
        $this->expectException(ValidationException::class);
        app(CreateProductAction::class)->handle(['category_public_id' => $category->public_id, 'name' => 'Crème', 'slug' => 'creme', 'regular_price_millimes' => 10000, 'promotional_price_millimes' => 10000, 'stock_quantity' => 5, 'is_active' => true, 'has_variants' => false]);
    }

    public function test_variant_mode_requires_an_explicit_resulting_stock_when_disabled(): void
    {
        $category = Category::query()->create(['name' => 'Visage', 'slug' => 'visage', 'is_active' => true, 'sort_order' => 0]);
        $product = app(CreateProductAction::class)->handle(['category_public_id' => $category->public_id, 'name' => 'Crème', 'slug' => 'creme', 'regular_price_millimes' => 10000, 'stock_quantity' => 5, 'is_active' => false, 'has_variants' => false]);
        app(SwitchProductVariantModeAction::class)->handle($product, true);
        $this->expectException(ValidationException::class);
        app(SwitchProductVariantModeAction::class)->handle($product->fresh(), false);
    }

    public function test_a_published_product_can_switch_to_variant_mode_without_manual_deactivation(): void
    {
        $category = Category::query()->create(['name' => 'Visage', 'slug' => 'visage', 'is_active' => true, 'sort_order' => 0]);
        $product = app(CreateProductAction::class)->handle(['category_public_id' => $category->public_id, 'name' => 'Crème active', 'slug' => 'creme-active', 'regular_price_millimes' => 10000, 'stock_quantity' => 5, 'is_active' => true, 'has_variants' => false]);

        $updated = app(SwitchProductVariantModeAction::class)->handle($product, true);

        $this->assertTrue($updated->has_variants);
        $this->assertNull($updated->stock_quantity);
    }

    public function test_product_list_exposes_the_sum_of_active_variant_stock(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $category = Category::query()->create(['name' => 'Visage', 'slug' => 'visage', 'is_active' => true, 'sort_order' => 0]);
        app(CreateProductAction::class)->handle(['category_public_id' => $category->public_id, 'name' => 'Rouge', 'slug' => 'rouge', 'regular_price_millimes' => 10000, 'stock_quantity' => null, 'is_active' => true, 'has_variants' => true, 'option_groups' => [['name' => 'Couleur', 'values' => [['client_key' => 'pink', 'value' => 'Rose'], ['client_key' => 'nude', 'value' => 'Nude']]]], 'variants' => [['option_value_client_keys' => ['pink'], 'stock_quantity' => 3, 'is_active' => true], ['option_value_client_keys' => ['nude'], 'stock_quantity' => 4, 'is_active' => false]]]);

        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/products')->assertOk()->assertJsonPath('data.data.0.active_variant_stock_quantity', 3);
    }

    public function test_it_replaces_variant_combinations_when_the_version_matches(): void
    {
        $category = Category::query()->create(['name' => 'Visage', 'slug' => 'visage', 'is_active' => true, 'sort_order' => 0]);
        $product = app(CreateProductAction::class)->handle(['category_public_id' => $category->public_id, 'name' => 'Rouge', 'slug' => 'rouge', 'regular_price_millimes' => 10000, 'stock_quantity' => null, 'is_active' => false, 'has_variants' => true, 'option_groups' => [['name' => 'Couleur', 'values' => [['client_key' => 'red', 'value' => 'Rouge']]]], 'variants' => [['option_value_client_keys' => ['red'], 'stock_quantity' => 2]]]);
        $updated = app(ReplaceProductVariantsAction::class)->handle($product, [['name' => 'Couleur', 'values' => [['client_key' => 'pink', 'value' => 'Rose']]]], [['option_value_client_keys' => ['pink'], 'stock_quantity' => 3]], $product->lock_version);
        $this->assertSame(3, $updated->variants->first()->stock_quantity);
        $this->assertSame(2, $updated->lock_version);
    }
}
