<?php

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductImage;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\OrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductDeletionAndOrderFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_admin_can_remove_a_product_without_erasing_its_order_history(): void
    {
        $product = $this->product('Huile de massage 250 ml');
        $order = $this->orderFor($product);
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $this->withMiddleware()
            ->actingAs($admin)
            ->withSession(['_token' => 'catalog-delete-test'])
            ->withHeader('X-CSRF-TOKEN', 'catalog-delete-test')
            ->deleteJson('/api/v1/admin/products/'.$product->public_id)
            ->assertOk();

        $this->assertSoftDeleted('products', ['id' => $product->id]);
        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name_snapshot' => $product->name,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'catalog.product_deleted',
            'auditable_id' => (string) $product->id,
        ]);
    }

    public function test_a_variant_product_with_a_large_gallery_can_be_permanently_deleted(): void
    {
        $category = Category::query()->create([
            'name' => 'Test suppression',
            'slug' => 'test-suppression',
            'is_active' => true,
        ]);
        $product = Product::query()->create([
            'category_id' => $category->id,
            'name' => 'Produit disposable',
            'slug' => 'produit-disposable',
            'regular_price_millimes' => 25_000,
            'stock_quantity' => null,
            'is_active' => false,
            'has_variants' => true,
        ]);
        $format = $product->optionGroups()->create(['name' => 'Format', 'sort_order' => 0]);
        $scent = $product->optionGroups()->create(['name' => 'Parfum', 'sort_order' => 1]);
        $formats = collect(range(1, 15))->map(fn (int $index) => $format->values()->create(['value' => 'Format '.$index]))->all();
        $scents = collect(range(1, 10))->map(fn (int $index) => $scent->values()->create(['value' => 'Parfum '.$index]))->all();

        foreach ($formats as $formatValue) {
            foreach ($scents as $scentValue) {
                $variant = $product->variants()->create([
                    'combination_key' => $formatValue->id.'-'.$scentValue->id,
                    'stock_quantity' => 1,
                    'is_active' => true,
                ]);
                $variant->values()->sync([$formatValue->id, $scentValue->id]);
            }
        }
        for ($index = 0; $index < 150; $index++) {
            ProductImage::query()->create([
                'product_id' => $product->id,
                'path' => null,
                'original_path' => null,
                'processing_status' => 'ready',
                'sort_order' => $index,
                'is_primary' => $index === 0,
            ]);
        }

        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $product->delete();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/admin/products/bulk-force-delete', ['public_ids' => [$product->public_id]])
            ->assertOk()
            ->assertJsonPath('data.deleted', 1);

        $this->assertDatabaseMissing('products', ['id' => $product->id]);
        $this->assertDatabaseMissing('product_images', ['product_id' => $product->id]);
        $this->assertDatabaseMissing('product_variants', ['product_id' => $product->id]);
        $this->assertDatabaseCount('product_variant_values', 0);
        $this->assertDatabaseCount('product_option_groups', 0);
    }

    public function test_orders_can_be_filtered_by_the_active_product_or_its_preserved_snapshot_name(): void
    {
        $matching = $this->product('Huile de massage 250 ml');
        $other = $this->product('Savon doux');
        $matchingOrder = $this->orderFor($matching);
        $this->orderFor($other);
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/orders?product_public_id='.$matching->public_id)
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.public_reference', $matchingOrder->public_reference);

        $matching->delete();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/orders?search=massage')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.public_reference', $matchingOrder->public_reference);
    }

    public function test_an_admin_can_remove_a_category_and_its_catalog_products_without_erasing_order_history(): void
    {
        $category = Category::query()->create([
            'name' => 'Soins corps',
            'slug' => 'soins-corps',
            'is_active' => true,
        ]);
        $product = Product::query()->create([
            'category_id' => $category->id,
            'name' => 'Huile corps',
            'slug' => 'huile-corps',
            'regular_price_millimes' => 25_000,
            'stock_quantity' => 8,
            'is_active' => true,
        ]);
        $order = $this->orderFor($product);
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $this->actingAs($admin)
            ->withSession(['_token' => 'category-delete-test'])
            ->withHeader('X-CSRF-TOKEN', 'category-delete-test')
            ->deleteJson('/api/v1/admin/categories/'.$category->public_id)
            ->assertOk()
            ->assertJsonPath('data.deleted_products', 1);

        $this->assertSoftDeleted('categories', ['id' => $category->id]);
        $this->assertSoftDeleted('products', ['id' => $product->id]);
        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name_snapshot' => $product->name,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'catalog.category_deleted',
            'auditable_id' => (string) $category->id,
        ]);
    }

    private function product(string $name): Product
    {
        $category = Category::query()->create([
            'name' => 'Corps '.Str::lower(Str::random(5)),
            'slug' => 'corps-'.Str::lower(Str::random(8)),
            'is_active' => true,
        ]);

        return Product::query()->create([
            'category_id' => $category->id,
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'regular_price_millimes' => 25_000,
            'stock_quantity' => 8,
            'is_active' => true,
            'has_variants' => false,
            'published_at' => now(),
        ]);
    }

    private function orderFor(Product $product): Order
    {
        $order = Order::query()->create([
            'checkout_idempotency_key' => (string) Str::uuid(),
            'status' => 'nouvelle',
            'customer_name' => 'Cliente test',
            'customer_phone' => '22123456',
            'customer_city' => 'Tunis',
            'customer_address' => '10 rue des Jasmins',
            'subtotal_millimes' => 25_000,
            'product_discount_millimes' => 0,
            'promo_code_discount_millimes' => 0,
            'shipping_fee_millimes' => 0,
            'total_millimes' => 25_000,
        ]);

        OrderItem::query()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name_snapshot' => $product->name,
            'regular_unit_price_millimes' => 25_000,
            'effective_unit_price_millimes' => 25_000,
            'quantity' => 1,
            'line_total_millimes' => 25_000,
        ]);

        return $order;
    }
}
