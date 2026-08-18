<?php

namespace Tests\Feature\Commerce;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Commerce\Actions\CreateGuestOrderAction;
use App\Domain\Commerce\Models\CheckoutField;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\Support\AssertsApiEnvelope;
use Tests\TestCase;

class GuestOrderContractTest extends TestCase
{
    use AssertsApiEnvelope;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    public function test_public_order_response_is_safe_and_contains_checkout_snapshot(): void
    {
        $product = $this->product(2, 15_000, 10_000);
        $response = $this->withHeader('Idempotency-Key', '4af95712-4d91-4c57-8d29-917324201111')->postJson('/api/v1/public/orders', $this->payload($product));

        $response->assertCreated()->assertJsonPath('data.order.status', 'nouvelle')->assertJsonPath('data.order.payment_method', 'cash_on_delivery')->assertJsonPath('data.order.customer.full_name', 'Client Test');
        $this->assertArrayHasKey('checkout_snapshot', $response->json('data.order'));
        $this->assertDoesNotLeakSecrets($response->json());
    }

    public function test_replayed_checkout_returns_same_order_and_conflicting_payload_rejects(): void
    {
        $product = $this->product(4, 15_000, 10_000);
        $key = '4af95712-4d91-4c57-8d29-917324201112';

        $first = $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/public/orders', $this->payload($product))->assertCreated();
        $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/public/orders', $this->payload($product))->assertOk()->assertJsonPath('data.order.public_reference', $first->json('data.order.public_reference'));
        $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/public/orders', $this->payload($product, 2))->assertConflict()->assertJsonPath('code', 'CHECKOUT_IDEMPOTENCY_CONFLICT');
    }

    public function test_unknown_or_inactive_fields_are_rejected_before_mutation(): void
    {
        $product = $this->product(2);
        $payload = $this->payload($product);
        $payload['customer']['unexpected'] = 'oops';

        $this->withHeader('Idempotency-Key', '4af95712-4d91-4c57-8d29-917324201113')->postJson('/api/v1/public/orders', $payload)->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_ERROR');
    }

    private function payload(Product $product, int $quantity = 1): array
    {
        $fields = CheckoutField::query()->where('is_active', true)->orderBy('sort_order')->get()->map(fn (CheckoutField $field) => $field->only(['key', 'label', 'type', 'is_required', 'options', 'sort_order']))->all();

        return ['checkout_schema_version' => app(CreateGuestOrderAction::class)->schemaVersion($fields), 'customer' => ['full_name' => 'Client Test', 'phone' => '22 123 456', 'city' => 'Tunis', 'governorate' => 'Tunis', 'address' => '10 rue de la Paix'], 'items' => [['product_public_id' => $product->public_id, 'variant_public_id' => null, 'quantity' => $quantity]]];
    }

    private function product(int $stock, int $regular = 15_000, ?int $promotion = null): Product
    {
        $category = Category::query()->create(['name' => 'Soin', 'slug' => 'soin-'.str()->random(6), 'is_active' => true]);

        return Product::query()->create(['category_id' => $category->id, 'name' => 'Huile', 'slug' => 'huile-'.str()->random(6), 'regular_price_millimes' => $regular, 'promotional_price_millimes' => $promotion, 'stock_quantity' => $stock, 'is_active' => true, 'has_variants' => false, 'published_at' => now()]);
    }
}
