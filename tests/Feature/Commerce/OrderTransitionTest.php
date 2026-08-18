<?php

namespace Tests\Feature\Commerce;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Commerce\Actions\ReconcileOrderItemsAction;
use App\Domain\Commerce\Actions\TransitionOrderStatusAction;
use App\Domain\Commerce\Actions\UpdateOrderCustomerAction;
use App\Domain\Commerce\Models\Order;
use App\Domain\Navex\Enums\NavexDeliveryStatus;
use App\Domain\Navex\Models\NavexShipment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class OrderTransitionTest extends TestCase
{
    use RefreshDatabase;

    public function test_cancellation_restores_stock_once_and_records_history(): void
    {
        [$order, $product] = $this->orderWithItem();
        $actor = User::factory()->create();
        $action = app(TransitionOrderStatusAction::class);

        $action->handle($order, 'annulee', 'Client indisponible', $actor->id);

        $this->assertSame(3, $product->fresh()->stock_quantity);
        $this->assertDatabaseHas('order_status_history', ['order_id' => $order->id, 'from_status' => 'nouvelle', 'to_status' => 'annulee', 'changed_by' => $actor->id]);
        $this->expectException(ValidationException::class);
        $action->handle($order->fresh(), 'annulee', 'Second essai', $actor->id);
    }

    public function test_operator_can_select_any_active_contact_status(): void
    {
        [$order] = $this->orderWithItem();
        $actor = User::factory()->create();
        $action = app(TransitionOrderStatusAction::class);

        $action->handle($order, 'tentative_1', null, $actor->id);
        $action->handle($order->fresh(), 'tentative_3', null, $actor->id);
        $action->handle($order->fresh(), 'confirmee', null, $actor->id);
        $action->handle($order->fresh(), 'nouvelle', 'Correction opérationnelle', $actor->id);

        $this->assertSame('nouvelle', $order->fresh()->status);
        $this->assertDatabaseHas('order_status_history', ['order_id' => $order->id, 'from_status' => 'confirmee', 'to_status' => 'nouvelle', 'reason' => 'Correction opérationnelle']);
    }

    public function test_invalid_transition_does_not_change_stock_or_status(): void
    {
        [$order, $product] = $this->orderWithItem();
        $actor = User::factory()->create();

        try {
            app(TransitionOrderStatusAction::class)->handle($order, 'nouvelle', null, $actor->id);
            $this->fail('Une transition vers le même statut doit être refusée.');
        } catch (ValidationException) {
        }

        $this->assertSame('nouvelle', $order->fresh()->status);
        $this->assertSame(2, $product->fresh()->stock_quantity);
    }

    public function test_order_cannot_be_cancelled_until_an_existing_navex_shipment_is_cancelled(): void
    {
        [$order] = $this->orderWithItem();
        $order->update(['status' => 'confirmee']);
        NavexShipment::query()->create(['order_id' => $order->id, 'status' => NavexDeliveryStatus::Pending, 'creation_mode' => 'automatic']);

        $this->expectException(ValidationException::class);
        app(TransitionOrderStatusAction::class)->handle($order->fresh(), 'annulee', null, User::factory()->create()->id);
    }

    public function test_reactivating_a_cancelled_order_reserves_stock_again(): void
    {
        [$order, $product] = $this->orderWithItem();
        $actor = User::factory()->create();
        $action = app(TransitionOrderStatusAction::class);

        $action->handle($order, 'annulee', null, $actor->id);
        $this->assertSame(3, $product->fresh()->stock_quantity);

        $action->handle($order->fresh(), 'tentative_2', 'Client rappelé', $actor->id);

        $this->assertSame('tentative_2', $order->fresh()->status);
        $this->assertSame(2, $product->fresh()->stock_quantity);
        $this->assertDatabaseMissing('inventory_restoration_markers', ['order_id' => $order->id, 'restoration_reason' => 'annulee']);
        $this->assertDatabaseHas('inventory_movements', ['product_id' => $product->id, 'type' => 'order_reactivation_deduction', 'quantity_delta' => -1]);
    }

    public function test_customer_delivery_update_allows_orders_without_a_navex_tracking_code(): void
    {
        [$order] = $this->orderWithItem();
        $action = app(UpdateOrderCustomerAction::class);
        $customer = ['full_name' => 'Client Modifié', 'phone' => '22 987 654', 'city' => 'Ariana', 'address' => 'Nouvelle rue'];
        $action->handle($order, 1, $customer);
        $this->assertSame('Client Modifié', $order->fresh()->customer_name);
        try {
            $action->handle($order->fresh(), 1, $customer);
            $this->fail('Une version périmée doit être refusée.');
        } catch (ValidationException) {
        }
        $order->update(['status' => 'tentative_1']);
        $action->handle($order->fresh(), 2, [...$customer, 'city' => 'Nabeul']);
        $this->assertSame('Nabeul', $order->fresh()->customer_city);
        $order->update(['status' => 'confirmee']);
        $action->handle($order->fresh(), 3, [...$customer, 'city' => 'Bizerte']);
        $this->assertSame('Bizerte', $order->fresh()->customer_city);
    }

    public function test_item_reconciliation_can_add_and_remove_products_atomically(): void
    {
        [$order, $oldProduct] = $this->orderWithItem();
        $replacement = Product::query()->create(['category_id' => $oldProduct->category_id, 'name' => 'Baume', 'slug' => 'baume-'.str()->random(6), 'regular_price_millimes' => 20_000, 'stock_quantity' => 4, 'is_active' => true]);
        $actor = User::factory()->create();

        app(ReconcileOrderItemsAction::class)->handle($order, 1, [
            ['product_public_id' => $oldProduct->public_id, 'variant_public_id' => null, 'quantity' => 1],
            ['product_public_id' => $replacement->public_id, 'variant_public_id' => null, 'quantity' => 2],
        ], $actor->id);
        $this->assertSame(2, $oldProduct->fresh()->stock_quantity);
        $this->assertSame(2, $replacement->fresh()->stock_quantity);
        $this->assertCount(2, $order->fresh()->items);

        app(ReconcileOrderItemsAction::class)->handle($order->fresh(), 2, [
            ['product_public_id' => $replacement->public_id, 'variant_public_id' => null, 'quantity' => 2],
        ], $actor->id);
        $this->assertSame(3, $oldProduct->fresh()->stock_quantity);
        $this->assertSame(2, $replacement->fresh()->stock_quantity);
        $this->assertCount(1, $order->fresh()->items);
    }

    public function test_item_reconciliation_rejects_duplicate_lines_without_changing_stock(): void
    {
        [$order, $product] = $this->orderWithItem();
        try {
            app(ReconcileOrderItemsAction::class)->handle($order, 1, [
                ['product_public_id' => $product->public_id, 'variant_public_id' => null, 'quantity' => 1],
                ['product_public_id' => $product->public_id, 'variant_public_id' => null, 'quantity' => 1],
            ], User::factory()->create()->id);
            $this->fail('Les doublons doivent être refusés.');
        } catch (ValidationException) {
        }
        $this->assertSame(2, $product->fresh()->stock_quantity);
        $this->assertCount(1, $order->fresh()->items);
    }

    /** @return array{Order, Product} */
    private function orderWithItem(): array
    {
        $category = Category::query()->create(['name' => 'Corps', 'slug' => 'corps-'.str()->random(6), 'is_active' => true]);
        $product = Product::query()->create(['category_id' => $category->id, 'name' => 'Huile', 'slug' => 'huile-'.str()->random(6), 'regular_price_millimes' => 10_000, 'stock_quantity' => 2, 'is_active' => true]);
        $order = Order::query()->create(['checkout_idempotency_key' => (string) str()->uuid(), 'checkout_payload_hash' => str()->random(64), 'status' => 'nouvelle', 'customer_name' => 'Client', 'customer_phone' => '22123456', 'customer_city' => 'Tunis', 'customer_address' => 'Rue test', 'subtotal_millimes' => 10_000, 'product_discount_millimes' => 0, 'promo_code_discount_millimes' => 0, 'shipping_fee_millimes' => 0, 'total_millimes' => 10_000]);
        $order->items()->create(['product_id' => $product->id, 'product_name_snapshot' => $product->name, 'regular_unit_price_millimes' => 10_000, 'effective_unit_price_millimes' => 10_000, 'quantity' => 1, 'line_total_millimes' => 10_000]);

        return [$order, $product];
    }
}
