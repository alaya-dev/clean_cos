<?php

namespace Tests\Feature\Commerce;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Checkout\Support\TunisianGovernorates;
use App\Domain\Commerce\Models\CheckoutField;
use App\Domain\Commerce\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class GuestOrderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        // StoreSettings caches survive RefreshDatabase; clear shipping values so
        // this endpoint contract always starts from the configured test defaults.
        foreach (['fixed_fee_millimes', 'free_threshold_enabled', 'free_threshold_millimes'] as $key) {
            Cache::forget('pc:cache:settings:v2:shipping.'.$key);
        }
    }

    public function test_order_uses_server_price_deducts_stock_and_replays_once(): void
    {
        $product = $this->product(2, 15_000, 10_000);
        $payload = $this->payload($product);
        $key = '4af95712-4d91-4c57-8d29-917324200001';

        $first = $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/public/orders', $payload)->assertCreated()->assertJsonPath('data.order.pricing.total.millimes', 20_000);
        $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/public/orders', $payload)->assertOk()->assertJsonPath('data.order.public_reference', $first->json('data.order.public_reference'));
        $this->assertSame(0, $product->fresh()->stock_quantity);
        $this->assertSame(1, Order::query()->count());
        $this->assertDatabaseHas('inventory_movements', ['type' => 'order_deduction', 'quantity_delta' => -2]);
    }

    public function test_checkout_exchange_fields_are_canonicalized_for_navex(): void
    {
        $product = $this->product(2, 15_000);
        CheckoutField::query()->create(['key' => 'echange', 'label' => 'Échange', 'type' => 'select', 'options' => ['Non', 'Oui'], 'is_required' => false, 'is_active' => true, 'is_system' => false, 'sort_order' => 10]);
        CheckoutField::query()->create(['key' => 'article', 'label' => 'Article à échanger', 'type' => 'text', 'is_required' => false, 'is_active' => true, 'is_system' => false, 'sort_order' => 11]);
        CheckoutField::query()->create(['key' => 'nb_echange', 'label' => 'Nombre à échanger', 'type' => 'number', 'is_required' => false, 'is_active' => true, 'is_system' => false, 'sort_order' => 12]);
        $payload = $this->payload($product, 1);
        $payload['customer']['echange'] = 'Oui';
        $payload['customer']['article'] = 'Ancien flacon';
        $payload['customer']['nb_echange'] = 2;

        $response = $this->withHeader('Idempotency-Key', '4af95712-4d91-4c57-8d29-917324200015')
            ->postJson('/api/v1/public/orders', $payload)
            ->assertCreated();

        $order = Order::query()->sole();
        self::assertTrue($order->is_exchange);
        self::assertSame('Ancien flacon', $order->exchange_article_designation);
        self::assertSame(2, $order->exchange_article_count);
    }

    public function test_order_rejects_stock_change_without_creating_partial_order(): void
    {
        $product = $this->product(1);

        $this->withHeader('Idempotency-Key', '4af95712-4d91-4c57-8d29-917324200002')->postJson('/api/v1/public/orders', $this->payload($product, 2))
            ->assertConflict()->assertJsonPath('code', 'INSUFFICIENT_STOCK');
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(1, $product->fresh()->stock_quantity);
    }

    public function test_idempotency_key_rejects_a_different_payload(): void
    {
        $product = $this->product(4);
        $key = '4af95712-4d91-4c57-8d29-917324200004';
        $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/public/orders', $this->payload($product, 1))->assertCreated();
        $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/public/orders', $this->payload($product, 2))->assertConflict()->assertJsonPath('code', 'CHECKOUT_IDEMPOTENCY_CONFLICT');
        $this->assertSame(1, Order::query()->count());
    }

    public function test_order_rejects_missing_required_checkout_field(): void
    {
        $product = $this->product(2);
        $payload = $this->payload($product);
        unset($payload['customer']['city']);

        $this->withHeader('Idempotency-Key', '4af95712-4d91-4c57-8d29-917324200005')->postJson('/api/v1/public/orders', $payload)->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_ERROR');
    }

    public function test_order_rejects_a_partial_governorate_query(): void
    {
        $product = $this->product(2);
        $payload = $this->payload($product);
        $payload['customer']['governorate'] = 'Tu';

        $this->withHeader('Idempotency-Key', '4af95712-4d91-4c57-8d29-917324200010')->postJson('/api/v1/public/orders', $payload)->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_ERROR');
        $this->assertSame(0, Order::query()->count());
    }

    public function test_phone_requires_eight_digits_but_accepts_formatted_international_numbers(): void
    {
        $product = $this->product(5);
        $short = $this->payload($product, 1);
        $short['customer']['phone'] = '123-4567';
        $shortResponse = $this->withHeader('Idempotency-Key', '4af95712-4d91-4c57-8d29-917324200011')->postJson('/api/v1/public/orders', $short)->assertUnprocessable();
        $this->assertSame('Le numéro de téléphone doit contenir au moins 8 chiffres.', $shortResponse->json('errors.customer.phone.0') ?? $shortResponse->json('errors')['customer.phone'][0]);

        $formatted = $this->payload($product, 1);
        $formatted['customer']['phone'] = '+216 (22) 123-456';
        $this->withHeader('Idempotency-Key', '4af95712-4d91-4c57-8d29-917324200012')->postJson('/api/v1/public/orders', $formatted)->assertCreated();

        $tooLong = $this->payload($product, 1);
        $tooLong['customer']['phone'] = '1234567890123456';
        $longResponse = $this->withHeader('Idempotency-Key', '4af95712-4d91-4c57-8d29-917324200013')->postJson('/api/v1/public/orders', $tooLong)->assertUnprocessable();
        $this->assertSame('Le numéro de téléphone doit contenir au moins 8 chiffres.', $longResponse->json('errors.customer.phone.0') ?? $longResponse->json('errors')['customer.phone'][0]);

        $international = $this->payload($product, 1);
        $international['customer']['phone'] = '00216 22 123 456';
        $this->withHeader('Idempotency-Key', '4af95712-4d91-4c57-8d29-917324200014')->postJson('/api/v1/public/orders', $international)->assertCreated();
    }

    public function test_public_checkout_fields_expose_the_canonical_governorate_source(): void
    {
        $fields = $this->getJson('/api/v1/public/checkout-fields')->assertOk()->json('data');
        $governorate = collect($fields)->firstWhere('key', 'governorate');

        $this->assertSame(TunisianGovernorates::ALL, $governorate['options']);
    }

    public function test_order_rejects_unknown_and_inactive_checkout_fields(): void
    {
        $product = $this->product(2);
        CheckoutField::query()->create(['key' => 'hidden_note', 'label' => 'Note cachée', 'type' => 'text', 'is_required' => false, 'is_active' => false, 'is_system' => false, 'sort_order' => 99]);

        $unknown = $this->payload($product);
        $unknown['customer']['unexpected'] = 'non';
        $this->withHeader('Idempotency-Key', '4af95712-4d91-4c57-8d29-917324200006')->postJson('/api/v1/public/orders', $unknown)->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_ERROR');

        $inactive = $this->payload($product);
        $inactive['customer']['hidden_note'] = 'non';
        $this->withHeader('Idempotency-Key', '4af95712-4d91-4c57-8d29-917324200007')->postJson('/api/v1/public/orders', $inactive)->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_ERROR');
    }

    public function test_order_rejects_invalid_options_and_normalizes_valid_checkout_fields(): void
    {
        $product = $this->product(2);
        CheckoutField::query()->create(['key' => 'delivery_method', 'label' => 'Mode de livraison', 'type' => 'select', 'options' => [['label' => 'Livraison', 'value' => 'livraison'], ['label' => 'Retrait', 'value' => 'retrait']], 'is_required' => true, 'is_active' => true, 'is_system' => false, 'sort_order' => 99]);

        $invalid = $this->payload($product);
        $invalid['customer']['delivery_method'] = 'express';
        $this->withHeader('Idempotency-Key', '4af95712-4d91-4c57-8d29-917324200008')->postJson('/api/v1/public/orders', $invalid)->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_ERROR');

        $valid = $this->payload($product);
        $valid['customer']['delivery_method'] = ' livraison ';
        $response = $this->withHeader('Idempotency-Key', '4af95712-4d91-4c57-8d29-917324200009')->postJson('/api/v1/public/orders', $valid)->assertCreated();
        $snapshot = collect($response->json('data.order.checkout_snapshot'))->firstWhere('field_key', 'delivery_method');
        $this->assertSame('livraison', $snapshot['value']);
    }

    /** @return array<string, mixed> */
    private function payload(Product $product, int $quantity = 2): array
    {
        $schemaVersion = $this->getJson('/api/v1/public/checkout-fields')->json('meta.schema_version');

        return ['checkout_schema_version' => $schemaVersion, 'customer' => ['full_name' => 'Client Test', 'phone' => '22 123 456', 'city' => 'Tunis', 'governorate' => 'Tunis', 'address' => '10 rue de la Paix'], 'items' => [['product_public_id' => $product->public_id, 'variant_public_id' => null, 'quantity' => $quantity, 'price' => 1]]];
    }

    private function product(int $stock, int $regular = 15_000, ?int $promotion = null): Product
    {
        $category = Category::query()->create(['name' => 'Soin', 'slug' => 'soin-'.str()->random(6), 'is_active' => true]);

        return Product::query()->create(['category_id' => $category->id, 'name' => 'Huile', 'slug' => 'huile-'.str()->random(6), 'regular_price_millimes' => $regular, 'promotional_price_millimes' => $promotion, 'stock_quantity' => $stock, 'is_active' => true, 'has_variants' => false, 'published_at' => now()]);
    }
}
