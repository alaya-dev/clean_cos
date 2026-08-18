<?php

namespace Tests\Integration\Commerce;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Commerce\Models\Order;
use App\Domain\Orders\Actions\RestoreOrderStockOnceAction;
use App\Domain\Orders\Models\InventoryRestorationMarker;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryRestorationConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_duplicate_restoration_is_durable_and_stock_is_incremented_once(): void
    {
        $category = Category::query()->create(['name' => 'Cat', 'slug' => 'cat-'.str()->random(8), 'is_active' => true]);
        $product = Product::query()->create(['category_id' => $category->id, 'name' => 'Produit', 'slug' => 'prod-'.str()->random(8), 'regular_price_millimes' => 1000, 'stock_quantity' => 0, 'is_active' => true]);
        $order = Order::query()->create(['checkout_idempotency_key' => (string) str()->uuid(), 'checkout_payload_hash' => str()->random(64), 'status' => 'annulee', 'customer_name' => 'Client', 'customer_phone' => '22123456', 'customer_city' => 'Tunis', 'customer_address' => 'Rue', 'subtotal_millimes' => 1000, 'product_discount_millimes' => 0, 'promo_code_discount_millimes' => 0, 'shipping_fee_millimes' => 0, 'total_millimes' => 1000]);
        $order->items()->create(['product_id' => $product->id, 'product_name_snapshot' => $product->name, 'regular_unit_price_millimes' => 1000, 'effective_unit_price_millimes' => 1000, 'quantity' => 2, 'line_total_millimes' => 2000]);
        $actor = User::factory()->create();
        $action = app(RestoreOrderStockOnceAction::class);

        $action->handle($order, $actor->id, 'annulee');
        $action->handle($order->fresh(), $actor->id, 'annulee');

        $this->assertSame(2, $product->fresh()->stock_quantity);
        $this->assertSame(1, InventoryRestorationMarker::query()->where('order_id', $order->id)->where('restoration_reason', 'annulee')->count());
    }

    public function test_bulk_restoration_marks_each_order_once(): void
    {
        $category = Category::query()->create(['name' => 'Cat', 'slug' => 'cat-'.str()->random(8), 'is_active' => true]);
        $product = Product::query()->create(['category_id' => $category->id, 'name' => 'Produit', 'slug' => 'prod-'.str()->random(8), 'regular_price_millimes' => 1000, 'stock_quantity' => 0, 'is_active' => true]);
        $orders = collect([1, 2])->map(function () use ($product): Order {
            $order = Order::query()->create(['checkout_idempotency_key' => (string) str()->uuid(), 'checkout_payload_hash' => str()->random(64), 'status' => 'annulee', 'customer_name' => 'Client', 'customer_phone' => '22123456', 'customer_city' => 'Tunis', 'customer_address' => 'Rue', 'subtotal_millimes' => 1000, 'product_discount_millimes' => 0, 'promo_code_discount_millimes' => 0, 'shipping_fee_millimes' => 0, 'total_millimes' => 1000]);
            $order->items()->create(['product_id' => $product->id, 'product_name_snapshot' => $product->name, 'regular_unit_price_millimes' => 1000, 'effective_unit_price_millimes' => 1000, 'quantity' => 1, 'line_total_millimes' => 1000]);

            return $order;
        });
        $actor = User::factory()->create();
        $action = app(RestoreOrderStockOnceAction::class);
        $orders->each(fn (Order $order) => $action->handle($order, $actor->id, 'annulee'));
        self::assertSame(2, $product->fresh()->stock_quantity);
        self::assertSame(2, InventoryRestorationMarker::query()->where('restoration_reason', 'annulee')->count());
    }
}
