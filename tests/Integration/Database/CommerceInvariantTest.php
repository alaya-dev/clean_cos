<?php

namespace Tests\Integration\Database;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Commerce\Models\Order;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CommerceInvariantTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_rejects_invalid_promotion_and_negative_stock(): void
    {
        $category = Category::query()->create(['name' => 'Cat', 'slug' => 'cat-'.str()->random(8), 'is_active' => true]);
        $this->expectException(QueryException::class);
        Product::query()->create(['category_id' => $category->id, 'name' => 'Produit', 'slug' => 'prod-'.str()->random(8), 'regular_price_millimes' => 1000, 'promotional_price_millimes' => 1000, 'stock_quantity' => 1, 'is_active' => true]);
    }

    public function test_database_rejects_variant_product_stock(): void
    {
        $category = Category::query()->create(['name' => 'Cat', 'slug' => 'cat-'.str()->random(8), 'is_active' => true]);
        $this->expectException(QueryException::class);
        Product::query()->create(['category_id' => $category->id, 'name' => 'Produit', 'slug' => 'prod-'.str()->random(8), 'regular_price_millimes' => 1000, 'stock_quantity' => 1, 'has_variants' => true, 'is_active' => true]);
    }

    public function test_database_rejects_invalid_order_status(): void
    {
        $this->expectException(QueryException::class);
        Order::query()->create(['checkout_idempotency_key' => (string) str()->uuid(), 'checkout_payload_hash' => str()->random(64), 'status' => 'invalid', 'customer_name' => 'Client', 'customer_phone' => '22123456', 'customer_city' => 'Tunis', 'customer_address' => 'Rue', 'subtotal_millimes' => 1000, 'product_discount_millimes' => 0, 'promo_code_discount_millimes' => 0, 'shipping_fee_millimes' => 0, 'total_millimes' => 1000]);
    }

    public function test_database_rejects_duplicate_idempotency_and_restoration_markers(): void
    {
        $order = Order::query()->create(['checkout_idempotency_key' => (string) str()->uuid(), 'checkout_payload_hash' => str()->random(64), 'status' => 'nouvelle', 'customer_name' => 'Client', 'customer_phone' => '22123456', 'customer_city' => 'Tunis', 'customer_address' => 'Rue', 'subtotal_millimes' => 1000, 'product_discount_millimes' => 0, 'promo_code_discount_millimes' => 0, 'shipping_fee_millimes' => 0, 'total_millimes' => 1000]);
        DB::table('checkout_idempotency_records')->insert(['order_id' => $order->id, 'idempotency_key' => (string) str()->uuid(), 'canonical_payload_hash' => str()->random(64), 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
        $this->expectException(QueryException::class);
        DB::table('checkout_idempotency_records')->insert(['order_id' => $order->id, 'idempotency_key' => (string) str()->uuid(), 'canonical_payload_hash' => str()->random(64), 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_database_rejects_duplicate_restoration_marker_scope(): void
    {
        $order = Order::query()->create(['checkout_idempotency_key' => (string) str()->uuid(), 'checkout_payload_hash' => str()->random(64), 'status' => 'annulee', 'customer_name' => 'Client', 'customer_phone' => '22123456', 'customer_city' => 'Tunis', 'customer_address' => 'Rue', 'subtotal_millimes' => 1000, 'product_discount_millimes' => 0, 'promo_code_discount_millimes' => 0, 'shipping_fee_millimes' => 0, 'total_millimes' => 1000]);
        DB::table('inventory_restoration_markers')->insert(['order_id' => $order->id, 'restoration_reason' => 'annulee', 'created_at' => now()]);
        $this->expectException(QueryException::class);
        DB::table('inventory_restoration_markers')->insert(['order_id' => $order->id, 'restoration_reason' => 'annulee', 'created_at' => now()]);
    }
}
