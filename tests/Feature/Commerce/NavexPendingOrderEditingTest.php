<?php

namespace Tests\Feature\Commerce;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Commerce\Models\Order;
use App\Domain\Navex\Enums\NavexDeliveryStatus;
use App\Domain\Navex\Models\NavexShipment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NavexPendingOrderEditingTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirmed_order_with_pending_raw_navex_status_allows_local_item_and_delivery_updates(): void
    {
        $actor = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        [$order, $product] = $this->orderWithShipment(' En attente ');

        $this->actingAs($actor)->getJson('/api/v1/admin/orders/'.$order->public_reference)
            ->assertOk()
            ->assertJsonPath('data.is_editable', true)
            ->assertJsonPath('data.is_delivery_editable', true)
            ->assertJsonPath('data.navex.manual_update_required', true);

        $this->actingAs($actor)->putJson('/api/v1/admin/orders/'.$order->public_reference.'/items', [
            'lock_version' => $order->lock_version,
            'items' => [['product_public_id' => $product->public_id, 'variant_public_id' => null, 'quantity' => 2]],
        ])->assertOk();

        $order = $order->fresh();
        $this->actingAs($actor)->patchJson('/api/v1/admin/orders/'.$order->public_reference, [
            'lock_version' => $order->lock_version,
            'customer' => ['full_name' => 'Client modifié', 'phone' => '22123456', 'city' => 'Nabeul', 'governorate' => 'Nabeul', 'address' => 'Nouvelle adresse'],
        ])->assertOk();

        $this->assertDatabaseHas('navex_shipments', ['order_id' => $order->id, 'tracking_code' => '983813788981']);
        $this->assertDatabaseCount('navex_shipments', 1);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'customer_city' => 'Nabeul']);
        $this->assertDatabaseHas('order_items', ['order_id' => $order->id, 'quantity' => 2]);
        $this->assertSame(9, $product->fresh()->stock_quantity);
    }

    public function test_exchange_fields_remain_locally_editable_without_recreating_existing_navex_shipment(): void
    {
        $actor = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        [$order] = $this->orderWithShipment('Au magasin');
        $trackingCode = $order->navexShipment->tracking_code;

        $this->actingAs($actor)->patchJson('/api/v1/admin/orders/'.$order->public_reference, [
            'lock_version' => $order->lock_version,
            'customer' => [
                'full_name' => 'Client échange', 'phone' => '22123456', 'city' => 'Nabeul',
                'governorate' => 'Nabeul', 'address' => 'Nouvelle adresse',
                'is_exchange' => 'Oui', 'exchange_article_designation' => 'Ancien article', 'exchange_article_count' => 2,
            ],
        ])->assertOk();

        $order = $order->fresh();
        self::assertNotNull($order);
        self::assertTrue($order->is_exchange);
        self::assertSame('Ancien article', $order->exchange_article_designation);
        self::assertSame(2, $order->exchange_article_count);
        self::assertSame($trackingCode, $order->navexShipment->tracking_code);
        self::assertSame(1, NavexShipment::query()->where('order_id', $order->id)->count());
    }

    public function test_tracking_error_does_not_lock_local_order_editing(): void
    {
        $actor = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        [$order] = $this->orderWithShipment('En attente');
        $order->navexShipment()->update(['status' => NavexDeliveryStatus::SynchronizationError, 'last_error_classification' => 'temporary_failure']);

        $this->actingAs($actor)->getJson('/api/v1/admin/orders/'.$order->public_reference)
            ->assertOk()
            ->assertJsonPath('data.is_editable', true)
            ->assertJsonPath('data.is_delivery_editable', true)
            ->assertJsonPath('data.navex.manual_update_required', true);
    }

    public function test_confirmed_order_with_an_unsent_local_shipment_remains_editable(): void
    {
        $actor = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        [$order, $product] = $this->orderWithShipment('En attente');
        $order->navexShipment()->update(['status' => NavexDeliveryStatus::PendingSend, 'tracking_code' => null, 'raw_status' => null, 'last_synchronized_at' => null]);

        $this->actingAs($actor)->getJson('/api/v1/admin/orders/'.$order->public_reference)
            ->assertOk()
            ->assertJsonPath('data.is_editable', true)
            ->assertJsonPath('data.is_delivery_editable', true)
            ->assertJsonPath('data.navex.manual_update_required', false);

        $this->actingAs($actor)->putJson('/api/v1/admin/orders/'.$order->public_reference.'/items', [
            'lock_version' => $order->lock_version,
            'items' => [['product_public_id' => $product->public_id, 'variant_public_id' => null, 'quantity' => 2]],
        ])->assertOk();

        $order = $order->fresh();
        $this->actingAs($actor)->patchJson('/api/v1/admin/orders/'.$order->public_reference, [
            'lock_version' => $order->lock_version,
            'customer' => ['full_name' => 'Client modifié', 'phone' => '22123456', 'city' => 'Nabeul', 'governorate' => 'Nabeul', 'address' => 'Nouvelle adresse'],
        ])->assertOk();
    }

    public function test_newly_accepted_navex_shipment_with_a_tracking_code_is_editable_before_the_first_tracking_sync(): void
    {
        $actor = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        [$order] = $this->orderWithShipment('En attente');
        $order->navexShipment()->update([
            'status' => NavexDeliveryStatus::Accepted,
            'raw_status' => null,
            'last_synchronized_at' => null,
            'last_error_classification' => null,
        ]);

        $this->actingAs($actor)->getJson('/api/v1/admin/orders/'.$order->public_reference)
            ->assertOk()
            ->assertJsonPath('data.is_editable', true)
            ->assertJsonPath('data.is_delivery_editable', true)
            ->assertJsonPath('data.navex.manual_update_required', true);
    }

    #[DataProvider('navexStatuses')]
    public function test_any_navex_status_keeps_local_order_updates_available(string $rawStatus): void
    {
        $actor = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        [$order, $product] = $this->orderWithShipment($rawStatus);

        $this->actingAs($actor)->getJson('/api/v1/admin/orders/'.$order->public_reference)
            ->assertOk()
            ->assertJsonPath('data.is_editable', true)
            ->assertJsonPath('data.is_delivery_editable', true)
            ->assertJsonPath('data.navex.manual_update_required', true);

        $this->actingAs($actor)->putJson('/api/v1/admin/orders/'.$order->public_reference.'/items', [
            'lock_version' => $order->lock_version,
            'items' => [['product_public_id' => $product->public_id, 'variant_public_id' => null, 'quantity' => 2]],
        ])->assertOk();

        $order = $order->fresh();
        $this->actingAs($actor)->patchJson('/api/v1/admin/orders/'.$order->public_reference, [
            'lock_version' => $order->lock_version,
            'customer' => ['full_name' => 'Client modifié', 'phone' => '22123456', 'city' => 'Nabeul', 'governorate' => 'Nabeul', 'address' => 'Nouvelle adresse'],
        ])->assertOk();
    }

    /** @return array<string, array{string}> */
    public static function navexStatuses(): array
    {
        return ['provider progress' => ['Au magasin'], 'known later state' => ['En cours'], 'unknown state' => ['Nouveau statut Navex']];
    }

    /** @return array{Order, Product} */
    private function orderWithShipment(string $rawStatus): array
    {
        $category = Category::query()->create(['name' => 'Soin', 'slug' => 'soin-'.str()->random(8), 'is_active' => true]);
        $product = Product::query()->create(['category_id' => $category->id, 'name' => 'Crème', 'slug' => 'creme-'.str()->random(8), 'regular_price_millimes' => 10_000, 'stock_quantity' => 10, 'is_active' => true]);
        $order = Order::query()->create([
            'checkout_idempotency_key' => (string) str()->uuid(),
            'checkout_payload_hash' => str()->random(64),
            'status' => 'confirmee',
            'customer_name' => 'Client test',
            'customer_phone' => '22123456',
            'customer_city' => 'Tunis',
            'customer_governorate' => 'Tunis',
            'customer_address' => 'Rue de la paix',
            'subtotal_millimes' => 10_000,
            'product_discount_millimes' => 0,
            'promo_code_discount_millimes' => 0,
            'shipping_fee_millimes' => 0,
            'total_millimes' => 10_000,
            'lock_version' => 1,
        ]);
        $order->items()->create(['product_id' => $product->id, 'product_name_snapshot' => $product->name, 'regular_unit_price_millimes' => 10_000, 'effective_unit_price_millimes' => 10_000, 'quantity' => 1, 'line_total_millimes' => 10_000]);
        NavexShipment::query()->create(['order_id' => $order->id, 'status' => NavexDeliveryStatus::Pending, 'tracking_code' => '983813788981', 'raw_status' => $rawStatus, 'last_synchronized_at' => now(), 'creation_mode' => 'manual']);

        return [$order, $product];
    }
}
