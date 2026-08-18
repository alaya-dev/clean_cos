<?php

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Actions\AdjustInventoryAction;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InventoryAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_adjustment_is_audited_and_cannot_make_stock_negative(): void
    {
        $category = Category::query()->create(['name' => 'Corps', 'slug' => 'corps', 'is_active' => true]);
        $product = Product::query()->create(['category_id' => $category->id, 'name' => 'Huile', 'slug' => 'huile', 'regular_price_millimes' => 10_000, 'stock_quantity' => 3, 'is_active' => true]);
        $actor = User::factory()->create();
        $action = app(AdjustInventoryAction::class);
        $action->handle($product, null, -2, 'Correction après comptage', $actor->id);
        $this->assertSame(1, $product->fresh()->stock_quantity);
        $this->assertDatabaseHas('inventory_movements', ['product_id' => $product->id, 'actor_user_id' => $actor->id, 'type' => 'manual_adjustment', 'quantity_delta' => -2]);
        try {
            $action->handle($product, null, -2, 'Erreur', $actor->id);
            $this->fail('Le stock négatif doit être refusé.');
        } catch (ValidationException) {
            $this->assertSame(1, $product->fresh()->stock_quantity);
        }
    }

    public function test_bulk_stock_supports_set_increase_and_decrease_atomically(): void
    {
        $category = Category::query()->create(['name' => 'Corps', 'slug' => 'corps-bulk', 'is_active' => true]);
        $first = Product::query()->create(['category_id' => $category->id, 'name' => 'Huile A', 'slug' => 'huile-a', 'regular_price_millimes' => 10_000, 'stock_quantity' => 3, 'is_active' => true]);
        $second = Product::query()->create(['category_id' => $category->id, 'name' => 'Huile B', 'slug' => 'huile-b', 'regular_price_millimes' => 10_000, 'stock_quantity' => 8, 'is_active' => true]);
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $payload = fn (string $operation, int $quantity): array => ['operation' => $operation, 'quantity' => $quantity, 'items' => [['product_public_id' => $first->public_id], ['product_public_id' => $second->public_id]]];

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/inventory/bulk-adjustments', $payload('set', 10))->assertOk();
        $this->assertSame(10, $first->fresh()->stock_quantity);
        $this->assertSame(10, $second->fresh()->stock_quantity);
        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/inventory/bulk-adjustments', $payload('increase', 2))->assertOk();
        $this->assertSame(12, $first->fresh()->stock_quantity);
        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/inventory/bulk-adjustments', $payload('decrease', 3))->assertOk();
        $this->assertSame(9, $first->fresh()->stock_quantity);
    }

    public function test_bulk_decrease_rolls_back_when_one_selected_record_would_be_negative(): void
    {
        $category = Category::query()->create(['name' => 'Corps', 'slug' => 'corps-rollback', 'is_active' => true]);
        $first = Product::query()->create(['category_id' => $category->id, 'name' => 'Huile A', 'slug' => 'huile-rollback-a', 'regular_price_millimes' => 10_000, 'stock_quantity' => 5, 'is_active' => true]);
        $second = Product::query()->create(['category_id' => $category->id, 'name' => 'Huile B', 'slug' => 'huile-rollback-b', 'regular_price_millimes' => 10_000, 'stock_quantity' => 1, 'is_active' => true]);
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/inventory/bulk-adjustments', ['operation' => 'decrease', 'quantity' => 2, 'items' => [['product_public_id' => $first->public_id], ['product_public_id' => $second->public_id]]]);
        $response->assertUnprocessable();
        $this->assertSame(5, $first->fresh()->stock_quantity);
        $this->assertSame(1, $second->fresh()->stock_quantity);
    }

    public function test_bulk_stock_can_target_a_variant_row_without_overwriting_parent_stock(): void
    {
        $category = Category::query()->create(['name' => 'Corps', 'slug' => 'corps-variant-bulk', 'is_active' => true]);
        $product = Product::query()->create(['category_id' => $category->id, 'name' => 'Spray', 'slug' => 'spray-bulk', 'regular_price_millimes' => 10_000, 'stock_quantity' => null, 'is_active' => true, 'has_variants' => true]);
        $variant = ProductVariant::query()->create(['product_id' => $product->id, 'combination_key' => 'size:100', 'stock_quantity' => 4, 'is_active' => true, 'is_current' => true]);
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/inventory/bulk-adjustments', ['operation' => 'increase', 'quantity' => 3, 'items' => [['product_public_id' => $product->public_id, 'variant_public_id' => $variant->public_id]]])->assertOk();
        $this->assertSame(7, $variant->fresh()->stock_quantity);
        $this->assertNull($product->fresh()->stock_quantity);
    }

    public function test_product_level_bulk_stock_updates_every_current_variant_and_reports_record_counts(): void
    {
        $category = Category::query()->create(['name' => 'Corps', 'slug' => 'corps-variant-product-bulk', 'is_active' => true]);
        $product = Product::query()->create(['category_id' => $category->id, 'name' => 'Spray groupé', 'slug' => 'spray-groupe', 'regular_price_millimes' => 10_000, 'stock_quantity' => null, 'is_active' => true, 'has_variants' => true]);
        $variants = collect([0, 4, 12])->map(fn (int $stock, int $index) => ProductVariant::query()->create(['product_id' => $product->id, 'combination_key' => 'size:'.$index, 'stock_quantity' => $stock, 'is_active' => true, 'is_current' => true]));
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/inventory/bulk-adjustments', ['operation' => 'set', 'quantity' => 20, 'items' => [['product_public_id' => $product->public_id]]]);
        $response->assertOk()->assertJsonPath('data.products_updated', 1)->assertJsonPath('data.stock_records_updated', 3)->assertJsonPath('data.variant_records_updated', 3);
        $this->assertSame([20, 20, 20], $product->variants()->orderBy('id')->pluck('stock_quantity')->all());

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/inventory/bulk-adjustments', ['operation' => 'increase', 'quantity' => 5, 'items' => [['product_public_id' => $product->public_id]]])->assertOk();
        $this->assertSame([25, 25, 25], $product->variants()->orderBy('id')->pluck('stock_quantity')->all());

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/inventory/bulk-adjustments', ['operation' => 'decrease', 'quantity' => 3, 'items' => [['product_public_id' => $product->public_id]]])->assertOk();
        $this->assertSame([22, 22, 22], $product->variants()->orderBy('id')->pluck('stock_quantity')->all());
        $this->assertCount(3, $variants);
    }

    public function test_variant_inventory_updates_bump_catalog_cache_for_storefront_stock(): void
    {
        $category = Category::query()->create(['name' => 'Visage', 'slug' => 'visage-stock-sync', 'is_active' => true]);
        $product = Product::query()->create(['category_id' => $category->id, 'name' => 'Stock synchronisé', 'slug' => 'stock-synchronise', 'regular_price_millimes' => 10_000, 'stock_quantity' => null, 'is_active' => true, 'has_variants' => true]);
        ProductVariant::query()->create(['product_id' => $product->id, 'combination_key' => 'color:rose', 'stock_quantity' => 0, 'is_active' => true, 'is_current' => true]);
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $this->get('/produits/'.$product->slug)->assertOk()->assertSee('"stock_quantity":0', false);
        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/inventory/bulk-adjustments', ['operation' => 'set', 'quantity' => 6, 'items' => [['product_public_id' => $product->public_id]]])->assertOk();
        $this->get('/produits/'.$product->slug)->assertOk()->assertSee('"stock_quantity":6', false);
    }

    public function test_bulk_stock_requires_catalog_authorization(): void
    {
        $this->postJson('/api/v1/admin/inventory/bulk-adjustments', [])->assertUnauthorized();
    }

    public function test_admin_can_filter_movement_history_by_product_type_and_date(): void
    {
        $category = Category::query()->create(['name' => 'Visage', 'slug' => 'visage', 'is_active' => true]);
        $matching = Product::query()->create(['category_id' => $category->id, 'name' => 'Sérum rose', 'slug' => 'serum-rose', 'regular_price_millimes' => 15_000, 'stock_quantity' => 5, 'is_active' => true]);
        $other = Product::query()->create(['category_id' => $category->id, 'name' => 'Huile dorée', 'slug' => 'huile-doree', 'regular_price_millimes' => 15_000, 'stock_quantity' => 5, 'is_active' => true]);
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $action = app(AdjustInventoryAction::class);
        $action->handle($matching, null, 1, 'Réception fournisseur', $admin->id);
        $action->handle($other, null, -1, 'Correction inventaire', $admin->id);

        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/inventory/movements?search=sérum&type=manual_adjustment&date_from='.now()->toDateString().'&date_to='.now()->toDateString())
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.product.name', 'Sérum rose');
    }
}
