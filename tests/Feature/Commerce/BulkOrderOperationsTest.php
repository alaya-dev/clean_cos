<?php

namespace Tests\Feature\Commerce;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductImage;
use App\Domain\Commerce\Models\Order;
use App\Domain\MetaTracking\Models\MetaEvent;
use App\Domain\Navex\Enums\NavexDeliveryStatus;
use App\Domain\Navex\Models\NavexShipment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BulkOrderOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_transition_compatible_orders_in_bulk(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $orders = collect([$this->makeOrder('nouvelle'), $this->makeOrder('nouvelle')]);

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/orders/bulk-transition', [
            'references' => $orders->pluck('public_reference')->all(),
            'to_status' => 'confirmee',
        ])->assertOk()->assertJsonPath('data.updated', 2);

        $this->assertSame('confirmee', $orders->first()->fresh()->status);
        $this->assertDatabaseCount('order_status_history', 2);
    }

    public function test_bulk_transition_allows_a_chosen_status_for_mixed_active_orders(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $new = $this->makeOrder('nouvelle');
        $confirmed = $this->makeOrder('confirmee');

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/orders/bulk-transition', [
            'references' => [$new->public_reference, $confirmed->public_reference],
            'to_status' => 'tentative_1',
        ])->assertOk()->assertJsonPath('data.updated', 2);

        $this->assertSame('tentative_1', $new->fresh()->status);
        $this->assertSame('tentative_1', $confirmed->fresh()->status);
    }

    public function test_bulk_transition_keeps_rows_already_in_the_chosen_status_unchanged(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $new = $this->makeOrder('nouvelle');
        $confirmed = $this->makeOrder('tentative_1');

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/orders/bulk-transition', [
            'references' => [$new->public_reference, $confirmed->public_reference],
            'to_status' => 'tentative_1',
        ])->assertOk()->assertJsonPath('data.updated', 1);

        $this->assertSame('tentative_1', $new->fresh()->status);
        $this->assertSame('tentative_1', $confirmed->fresh()->status);
        $this->assertDatabaseCount('order_status_history', 1);
    }

    public function test_bulk_confirmation_uses_the_same_transition_flow(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $first = $this->makeOrder('nouvelle');
        $second = $this->makeOrder('nouvelle');

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/orders/bulk-transition', [
            'references' => [$first->public_reference, $second->public_reference],
            'to_status' => 'confirmee',
        ])->assertOk()->assertJsonPath('data.updated', 2);

        $this->assertSame('confirmee', $first->fresh()->status);
        $this->assertSame('confirmee', $second->fresh()->status);
        $this->assertDatabaseCount('order_status_history', 2);
    }

    public function test_bulk_archiving_hides_orders_without_erasing_their_history(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $order = $this->makeOrder('nouvelle');

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/orders/bulk-archive', [
            'references' => [$order->public_reference],
        ])->assertOk()->assertJsonPath('data.archived', 1);

        $this->assertNotNull($order->fresh()->archived_at);
        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/orders')
            ->assertOk()->assertJsonMissing(['public_reference' => $order->public_reference]);
        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/orders?archived=1')
            ->assertOk()->assertJsonFragment(['public_reference' => $order->public_reference]);
        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/orders/'.$order->public_reference)->assertOk();

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/orders/bulk-restore', [
            'references' => [$order->public_reference],
        ])->assertOk()->assertJsonPath('data.restored', 1);
        $this->assertNull($order->fresh()->archived_at);
        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/orders')
            ->assertOk()->assertJsonFragment(['public_reference' => $order->public_reference]);
    }

    public function test_order_detail_includes_the_selected_variant_values(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $category = Category::query()->create(['name' => 'Corps', 'slug' => 'corps-'.str()->random(6), 'is_active' => true]);
        $product = Product::query()->create(['category_id' => $category->id, 'name' => 'Baume', 'slug' => 'baume-'.str()->random(6), 'regular_price_millimes' => 10_000, 'stock_quantity' => null, 'has_variants' => true, 'is_active' => true]);
        $group = $product->optionGroups()->create(['name' => 'Format']);
        $value = $group->values()->create(['value' => '50 ml']);
        $variant = $product->variants()->create(['sku' => null, 'combination_key' => '50-ml', 'stock_quantity' => 3, 'is_active' => true]);
        $variant->values()->attach($value);
        $order = $this->makeOrder('nouvelle');
        $order->items()->create(['product_id' => $product->id, 'product_variant_id' => $variant->id, 'product_name_snapshot' => $product->name, 'regular_unit_price_millimes' => 10_000, 'effective_unit_price_millimes' => 10_000, 'quantity' => 1, 'line_total_millimes' => 10_000]);

        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/orders/'.$order->public_reference)
            ->assertOk()
            ->assertJsonPath('data.order.items.0.variant.values.0.value', '50 ml')
            ->assertJsonPath('data.order.designation', '1 "Baume"');
    }

    public function test_admin_can_load_active_products_for_order_item_editing(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $category = Category::query()->create(['name' => 'Visage', 'slug' => 'visage-'.str()->random(6), 'is_active' => true]);
        $product = Product::query()->create(['category_id' => $category->id, 'name' => 'Sérum éclat', 'slug' => 'serum-eclat-'.str()->random(6), 'regular_price_millimes' => 10_000, 'stock_quantity' => 3, 'is_active' => true]);
        ProductImage::query()->create(['product_id' => $product->id, 'path' => 'products/serum-eclat.webp', 'processing_status' => 'ready', 'is_primary' => true]);
        $order = $this->makeOrder('nouvelle');

        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/orders/'.$order->public_reference.'/available-products?search=Sérum')
            ->assertOk()
            ->assertJsonPath('data.0.public_id', $product->public_id)
            ->assertJsonPath('data.0.name', $product->name)
            ->assertJsonPath('data.0.images.0.public_url', '/storage/products/serum-eclat.webp');
    }

    public function test_order_item_product_search_includes_active_variants_for_selection(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $category = Category::query()->create(['name' => 'Visage', 'slug' => 'visage-'.str()->random(6), 'is_active' => true]);
        $product = Product::query()->create(['category_id' => $category->id, 'name' => 'Sérum variantes', 'slug' => 'serum-variantes-'.str()->random(6), 'regular_price_millimes' => 10_000, 'stock_quantity' => null, 'has_variants' => true, 'is_active' => true]);
        $variant = $product->allVariants()->create(['sku' => '30 ml', 'combination_key' => '30-ml', 'stock_quantity' => 3, 'is_active' => true, 'is_current' => false]);
        $order = $this->makeOrder('nouvelle');

        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/orders/'.$order->public_reference.'/available-products?search=Sérum')
            ->assertOk()
            ->assertJsonPath('data.0.public_id', $product->public_id)
            ->assertJsonPath('data.0.variants.0.public_id', $variant->public_id)
            ->assertJsonPath('data.0.variants.0.is_active', true);
    }

    public function test_order_list_includes_a_primary_product_thumbnail_and_purchased_product_names(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $category = Category::query()->create(['name' => 'Visage', 'slug' => 'visage-'.str()->random(6), 'is_active' => true]);
        $product = Product::query()->create(['category_id' => $category->id, 'name' => 'Sérum', 'slug' => 'serum-'.str()->random(6), 'regular_price_millimes' => 10_000, 'stock_quantity' => 3, 'is_active' => true]);
        ProductImage::query()->create(['product_id' => $product->id, 'path' => 'products/serum.webp', 'processing_status' => 'ready', 'is_primary' => true]);
        $order = $this->makeOrder('nouvelle');
        $order->items()->create(['product_id' => $product->id, 'product_name_snapshot' => $product->name, 'regular_unit_price_millimes' => 10_000, 'effective_unit_price_millimes' => 10_000, 'quantity' => 1, 'line_total_millimes' => 10_000]);
        $secondProduct = Product::query()->create(['category_id' => $category->id, 'name' => 'Crème éclat', 'slug' => 'creme-eclat-'.str()->random(6), 'regular_price_millimes' => 12_000, 'stock_quantity' => 2, 'is_active' => true]);
        $order->items()->create(['product_id' => $secondProduct->id, 'product_name_snapshot' => $secondProduct->name, 'regular_unit_price_millimes' => 12_000, 'effective_unit_price_millimes' => 12_000, 'quantity' => 1, 'line_total_millimes' => 12_000]);

        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/orders')
            ->assertOk()
            ->assertJsonPath('data.data.0.product_thumbnail_url', '/storage/products/serum.webp')
            ->assertJsonPath('data.data.0.product_names.0', 'Sérum')
            ->assertJsonPath('data.data.0.product_names.1', 'Crème éclat');
    }

    public function test_archived_order_can_be_permanently_deleted_with_direct_operational_details_and_audit_preserved(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $order = $this->makeOrder('annulee');
        $order->update(['archived_at' => now()]);
        $order->items()->create(['product_name_snapshot' => 'Produit test', 'regular_unit_price_millimes' => 10_000, 'effective_unit_price_millimes' => 10_000, 'quantity' => 1, 'line_total_millimes' => 10_000]);
        $order->checkoutValues()->create(['field_key_snapshot' => 'city', 'label_snapshot' => 'Ville', 'type_snapshot' => 'text', 'value' => 'Tunis']);
        $order->notes()->create(['user_id' => $admin->id, 'body' => 'Note interne', 'created_at' => now()]);
        DB::table('order_status_history')->insert(['order_id' => $order->id, 'from_status' => null, 'to_status' => 'annulee', 'created_at' => now()]);
        DB::table('checkout_idempotency_records')->insert(['order_id' => $order->id, 'idempotency_key' => (string) str()->uuid(), 'canonical_payload_hash' => str()->random(64), 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('inventory_restoration_markers')->insert(['order_id' => $order->id, 'restoration_reason' => 'cancelled', 'created_at' => now()]);

        $this->actingAs($admin, 'sanctum')->deleteJson('/api/v1/admin/orders/bulk', ['references' => [$order->public_reference]])
            ->assertOk()
            ->assertJsonPath('data.deleted', 1);

        $this->assertDatabaseMissing('orders', ['id' => $order->id]);
        $this->assertDatabaseMissing('order_items', ['order_id' => $order->id]);
        $this->assertDatabaseMissing('order_checkout_values', ['order_id' => $order->id]);
        $this->assertDatabaseMissing('order_notes', ['order_id' => $order->id]);
        $this->assertDatabaseMissing('checkout_idempotency_records', ['order_id' => $order->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'order.bulk_permanently_deleted']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'order.permanently_deleted', 'auditable_id' => $order->public_reference]);
    }

    public function test_permanent_delete_requires_archiving_first_and_does_not_touch_active_stock(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $category = Category::query()->create(['name' => 'Cheveux', 'slug' => 'cheveux-'.str()->random(6), 'is_active' => true]);
        $product = Product::query()->create(['category_id' => $category->id, 'name' => 'Masque', 'slug' => 'masque-'.str()->random(6), 'regular_price_millimes' => 10_000, 'stock_quantity' => 1, 'is_active' => true]);
        $activeOrder = $this->makeOrder('nouvelle');
        $activeOrder->items()->create(['product_id' => $product->id, 'product_name_snapshot' => $product->name, 'regular_unit_price_millimes' => 10_000, 'effective_unit_price_millimes' => 10_000, 'quantity' => 2, 'line_total_millimes' => 20_000]);

        $this->actingAs($admin, 'sanctum')->deleteJson('/api/v1/admin/orders/bulk', ['references' => [$activeOrder->public_reference]])
            ->assertStatus(422)
            ->assertJsonPath('code', 'ORDER_DELETE_ARCHIVE_REQUIRED');
        $this->assertDatabaseHas('orders', ['id' => $activeOrder->id, 'archived_at' => null]);
        $this->assertSame(1, $product->fresh()->stock_quantity);

        $archivedOrder = $this->makeOrder('annulee');
        $archivedOrder->update(['archived_at' => now()]);
        MetaEvent::query()->create([
            'event_name' => 'Purchase',
            'order_id' => $archivedOrder->id,
            'event_time' => now(),
            'consent_policy_version' => 1,
            'marketing_consent' => true,
            'payload_hash' => str()->random(64),
            'capi_state' => 'temporary_failure',
        ]);

        $this->actingAs($admin, 'sanctum')->deleteJson('/api/v1/admin/orders/bulk', ['references' => [$archivedOrder->public_reference]])
            ->assertStatus(422)
            ->assertJsonPath('code', 'ORDER_DELETE_META_DELIVERY_PENDING');
        $this->assertDatabaseHas('orders', ['id' => $archivedOrder->id]);
    }

    public function test_permanent_delete_removes_a_terminal_navex_shipment_with_the_order(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $order = $this->makeOrder('annulee');
        $order->update(['archived_at' => now()]);
        $shipment = NavexShipment::query()->create([
            'order_id' => $order->id,
            'status' => NavexDeliveryStatus::Cancelled,
            'creation_mode' => 'manual',
        ]);
        $shipment->statusHistory()->create(['status' => NavexDeliveryStatus::Cancelled, 'recorded_at' => now()]);

        $this->actingAs($admin, 'sanctum')->deleteJson('/api/v1/admin/orders/bulk', ['references' => [$order->public_reference]])
            ->assertOk()
            ->assertJsonPath('data.deleted', 1);

        $this->assertDatabaseMissing('orders', ['id' => $order->id]);
        $this->assertDatabaseMissing('navex_shipments', ['id' => $shipment->id]);
        $this->assertDatabaseMissing('navex_shipment_status_history', ['navex_shipment_id' => $shipment->id]);
    }

    public function test_permanent_delete_rejects_an_order_pending_at_navex_without_a_server_error(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $order = $this->makeOrder('confirmee');
        $order->update(['archived_at' => now()]);
        NavexShipment::query()->create([
            'order_id' => $order->id,
            'status' => NavexDeliveryStatus::Pending,
            'tracking_code' => 'NX-100',
            'creation_mode' => 'manual',
        ]);

        $this->actingAs($admin, 'sanctum')->deleteJson('/api/v1/admin/orders/bulk', ['references' => [$order->public_reference]])
            ->assertStatus(422)
            ->assertJsonPath('code', 'ORDER_DELETE_META_DELIVERY_PENDING')
            ->assertJsonPath('message', 'Cette commande est encore active chez Navex (En attente chez Navex). Annulez d’abord le colis dans Livraison Navex et attendez la confirmation avant de supprimer la commande.');

        $this->assertDatabaseHas('orders', ['id' => $order->id]);
    }

    public function test_permanent_delete_rejects_a_tracked_navex_synchronization_error(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $order = $this->makeOrder('confirmee');
        $order->update(['archived_at' => now()]);
        NavexShipment::query()->create([
            'order_id' => $order->id,
            'status' => NavexDeliveryStatus::SynchronizationError,
            'tracking_code' => 'NX-101',
            'creation_mode' => 'manual',
        ]);

        $this->actingAs($admin, 'sanctum')->deleteJson('/api/v1/admin/orders/bulk', ['references' => [$order->public_reference]])
            ->assertStatus(422)
            ->assertJsonPath('code', 'ORDER_DELETE_META_DELIVERY_PENDING');

        $this->assertDatabaseHas('orders', ['id' => $order->id]);
    }

    private function makeOrder(string $status): Order
    {
        return Order::query()->create([
            'checkout_idempotency_key' => (string) str()->uuid(),
            'checkout_payload_hash' => str()->random(64),
            'status' => $status,
            'lock_version' => 1,
            'customer_name' => 'Client test',
            'customer_phone' => '22123456',
            'customer_city' => 'Tunis',
            'customer_address' => 'Rue test',
            'subtotal_millimes' => 10_000,
            'product_discount_millimes' => 0,
            'promo_code_discount_millimes' => 0,
            'shipping_fee_millimes' => 0,
            'total_millimes' => 10_000,
        ]);
    }

    /** @return array{Order, Product} */
    private function orderWithProduct(string $status): array
    {
        $category = Category::query()->create(['name' => 'Soins', 'slug' => 'soins-'.str()->random(6), 'is_active' => true]);
        $product = Product::query()->create(['category_id' => $category->id, 'name' => 'Crème', 'slug' => 'creme-'.str()->random(6), 'regular_price_millimes' => 10_000, 'stock_quantity' => 2, 'is_active' => true]);
        $order = $this->makeOrder($status);
        $order->items()->create(['product_id' => $product->id, 'product_name_snapshot' => $product->name, 'regular_unit_price_millimes' => 10_000, 'effective_unit_price_millimes' => 10_000, 'quantity' => 1, 'line_total_millimes' => 10_000]);

        return [$order, $product];
    }
}
