<?php

namespace Tests\Feature\Commerce;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Commerce\Actions\ReconcileOrderItemsAction;
use App\Domain\Commerce\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderTotalUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_override_the_total_without_changing_items_or_stock(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        [$order, $product] = $this->orderWithProduct('confirmee');
        $stockBefore = $product->stock_quantity;

        $this->actingAs($admin)->patchJson('/api/v1/admin/orders/'.$order->public_reference.'/total', [
            'lock_version' => $order->lock_version,
            'total_millimes' => 25_500,
        ])->assertOk()
            ->assertJsonPath('data.total_millimes', 25_500)
            ->assertJsonPath('data.manual_total_millimes', 25_500);

        $updated = $order->fresh();
        self::assertNotNull($updated);
        self::assertSame(25_500, $updated->total_millimes);
        self::assertSame(25_500, $updated->manual_total_millimes);
        self::assertSame(1, $updated->items()->count());
        self::assertSame($stockBefore, $product->fresh()->stock_quantity);
    }

    public function test_total_override_is_available_regardless_of_order_status(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        [$order] = $this->orderWithProduct('annulee');

        $this->actingAs($admin)->patchJson('/api/v1/admin/orders/'.$order->public_reference.'/total', [
            'lock_version' => $order->lock_version,
            'total_millimes' => 1_000,
        ])->assertOk();

        self::assertSame(1_000, $order->fresh()->total_millimes);
    }

    public function test_recalculating_articles_keeps_a_manual_total_until_the_admin_clears_it(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        [$order, $product] = $this->orderWithProduct('confirmee');
        $order->update(['manual_total_millimes' => 30_000, 'total_millimes' => 30_000]);

        $updated = app(ReconcileOrderItemsAction::class)->handle(
            $order->fresh(),
            $order->lock_version,
            [['product_public_id' => $product->public_id, 'variant_public_id' => null, 'quantity' => 2]],
            $admin->id,
        );

        self::assertSame(20_000, $updated->subtotal_millimes);
        self::assertSame(30_000, $updated->manual_total_millimes);
        self::assertSame(30_000, $updated->total_millimes);
    }

    public function test_clearing_the_override_restores_the_authoritative_calculated_total(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        [$order] = $this->orderWithProduct('confirmee');
        $order->update(['manual_total_millimes' => 25_500, 'total_millimes' => 25_500]);

        $this->actingAs($admin)->patchJson('/api/v1/admin/orders/'.$order->public_reference.'/total', [
            'lock_version' => $order->lock_version,
            'total_millimes' => null,
        ])->assertOk()
            ->assertJsonPath('data.total_millimes', 10_000)
            ->assertJsonPath('data.manual_total_millimes', null);

        $updated = $order->fresh();
        self::assertNotNull($updated);
        self::assertSame(10_000, $updated->total_millimes);
        self::assertNull($updated->manual_total_millimes);
    }

    public function test_total_update_rejects_a_stale_lock_version(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        [$order] = $this->orderWithProduct('confirmee');

        $this->actingAs($admin)->patchJson('/api/v1/admin/orders/'.$order->public_reference.'/total', [
            'lock_version' => 99,
            'total_millimes' => 25_500,
        ])->assertStatus(409)
            ->assertJsonPath('code', 'ORDER_VERSION_CONFLICT');
    }

    /** @return array{Order, Product} */
    private function orderWithProduct(string $status): array
    {
        $category = Category::query()->create(['name' => 'Soin', 'slug' => 'soin-'.str()->random(8), 'is_active' => true]);
        $product = Product::query()->create([
            'category_id' => $category->id,
            'name' => 'Baume',
            'slug' => 'baume-'.str()->random(8),
            'regular_price_millimes' => 10_000,
            'stock_quantity' => 5,
            'is_active' => true,
        ]);
        $order = Order::query()->create([
            'checkout_idempotency_key' => (string) str()->uuid(),
            'checkout_payload_hash' => str()->random(64),
            'status' => $status,
            'customer_name' => 'Client test',
            'customer_phone' => '22123456',
            'customer_city' => 'Tunis',
            'customer_address' => 'Rue test',
            'subtotal_millimes' => 10_000,
            'product_discount_millimes' => 0,
            'promo_code_discount_millimes' => 0,
            'shipping_fee_millimes' => 0,
            'total_millimes' => 10_000,
            'lock_version' => 1,
        ]);
        $order->items()->create([
            'product_id' => $product->id,
            'product_name_snapshot' => $product->name,
            'regular_unit_price_millimes' => 10_000,
            'effective_unit_price_millimes' => 10_000,
            'quantity' => 1,
            'line_total_millimes' => 10_000,
        ]);

        return [$order, $product];
    }
}
