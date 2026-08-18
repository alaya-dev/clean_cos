<?php

namespace Tests\Feature\Commerce;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Commerce\Actions\CreateGuestOrderAction;
use App\Domain\Commerce\Actions\ReconcileOrderItemsAction;
use App\Domain\Commerce\Models\CheckoutField;
use App\Domain\Commerce\Models\Order;
use App\Domain\Settings\Services\StoreSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class ReconcileOrderItemsTest extends TestCase
{
    use RefreshDatabase;

    public function test_reconcile_uses_same_shipping_rule_and_preserves_stock_locking(): void
    {
        $actor = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        app(StoreSettings::class)->update([
            'shipping.fixed_fee_millimes' => 2_000,
            'shipping.free_threshold_enabled' => true,
            'shipping.free_threshold_millimes' => 10_000,
        ], $actor->id);
        $product = $this->product(5, 7_000);
        $order = $this->createOrder($product);

        $updated = app(ReconcileOrderItemsAction::class)->handle($order, $order->lock_version, [['product_public_id' => $product->public_id, 'variant_public_id' => null, 'quantity' => 2]], $actor->id);

        $this->assertSame(14_000, $updated->total_millimes);
        $this->assertSame(0, $updated->shipping_fee_millimes);
    }

    public function test_reconcile_uses_the_canonical_promotion_price(): void
    {
        $product = $this->product(5, 12_000, 10_000);
        $order = $this->createOrder($product);
        $actor = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $updated = app(ReconcileOrderItemsAction::class)->handle($order, $order->lock_version, [[
            'product_public_id' => $product->public_id,
            'variant_public_id' => null,
            'quantity' => 2,
        ]], $actor->id);

        $this->assertSame(20_000, $updated->subtotal_millimes);
        $this->assertSame(4_000, $updated->product_discount_millimes);
        $this->assertSame(20_000, $updated->total_millimes);
    }

    public function test_reconcile_keeps_an_unavailable_historical_item_until_an_admin_explicitly_removes_it(): void
    {
        $actor = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        app(StoreSettings::class)->update([
            'shipping.fixed_fee_millimes' => 0,
            'shipping.free_threshold_enabled' => false,
            'shipping.free_threshold_millimes' => null,
        ], $actor->id);
        $historical = $this->product(5, 7_000);
        $replacement = $this->product(5, 9_000);
        $order = $this->createOrder($historical);
        $historical->delete();

        $kept = app(ReconcileOrderItemsAction::class)->handle($order, $order->lock_version, [[
            'product_public_id' => $replacement->public_id,
            'variant_public_id' => null,
            'quantity' => 1,
        ]], $actor->id);

        $this->assertCount(2, $kept->items);
        $this->assertSame(23_000, $kept->total_millimes);

        $historicalItem = $kept->items->firstWhere('product_id', $historical->id);
        if ($historicalItem === null) {
            throw new LogicException('L’article historique doit être conservé avant son retrait explicite.');
        }

        $removed = app(ReconcileOrderItemsAction::class)->handle($kept, $kept->lock_version, [[
            'product_public_id' => $replacement->public_id,
            'variant_public_id' => null,
            'quantity' => 1,
        ]], $actor->id, [$historicalItem->id]);

        $this->assertCount(1, $removed->items);
        $this->assertSame(9_000, $removed->total_millimes);
    }

    private function createOrder(Product $product): Order
    {
        $payload = $this->payload($product);
        $response = $this->withHeader('Idempotency-Key', '4af95712-4d91-4c57-8d29-917324201301')->postJson('/api/v1/public/orders', $payload)->assertCreated();

        return Order::query()->where('public_reference', $response->json('data.order.public_reference'))->firstOrFail();
    }

    /** @return array{checkout_schema_version: string, customer: array{full_name: string, phone: string, city: string, governorate: string, address: string}, items: list<array{product_public_id: string, variant_public_id: null, quantity: int}>} */
    private function payload(Product $product): array
    {
        $fields = CheckoutField::query()->where('is_active', true)->orderBy('sort_order')->get()->map(fn (CheckoutField $field) => $field->only(['key', 'label', 'type', 'is_required', 'options', 'sort_order']))->all();

        return ['checkout_schema_version' => app(CreateGuestOrderAction::class)->schemaVersion($fields), 'customer' => ['full_name' => 'Client Test', 'phone' => '22 123 456', 'city' => 'Tunis', 'governorate' => 'Tunis', 'address' => '10 rue de la Paix'], 'items' => [['product_public_id' => $product->public_id, 'variant_public_id' => null, 'quantity' => 2]]];
    }

    private function product(int $stock, int $regular = 12_000, ?int $promo = null): Product
    {
        $category = Category::query()->create(['name' => 'Corps', 'slug' => 'corps-'.str()->random(6), 'is_active' => true]);

        return Product::query()->create(['category_id' => $category->id, 'name' => 'Baume', 'slug' => 'baume-'.str()->random(6), 'regular_price_millimes' => $regular, 'promotional_price_millimes' => $promo, 'stock_quantity' => $stock, 'is_active' => true, 'has_variants' => false, 'published_at' => now()]);
    }
}
