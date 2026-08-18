<?php

namespace Tests\Feature\Commerce;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Commerce\Actions\CreateGuestOrderAction;
use App\Domain\Commerce\Models\CheckoutField;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CartQuoteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        config([
            'commerce.shipping_fixed_fee_millimes' => 0,
            'commerce.shipping_free_threshold_millimes' => null,
        ]);
        Cache::forget('pc:cache:settings:v2:shipping.fixed_fee_millimes');
        Cache::forget('pc:cache:settings:v2:shipping.free_threshold_enabled');
        Cache::forget('pc:cache:settings:v2:shipping.free_threshold_millimes');
    }

    public function test_quote_uses_server_price_and_does_not_reserve_stock(): void
    {
        $product = $this->product(5, 12_000, 9_000);
        $response = $this->postJson('/api/v1/public/cart/quote', ['items' => [['product_public_id' => $product->public_id, 'variant_public_id' => null, 'quantity' => 2, 'effective_unit_price_millimes' => 1]]]);

        $response->assertOk()->assertJsonPath('data.items.0.effective_unit_price.millimes', 9_000)->assertJsonPath('data.pricing.total.millimes', 18_000)->assertJsonPath('data.can_checkout', true);
        $this->assertSame(5, $product->fresh()->stock_quantity);
    }

    public function test_quote_adjusts_a_stale_quantity_to_current_stock(): void
    {
        $product = $this->product(1);

        $this->postJson('/api/v1/public/cart/quote', ['items' => [['product_public_id' => $product->public_id, 'variant_public_id' => null, 'quantity' => 2]]])
            ->assertOk()->assertJsonPath('data.items.0.is_available', true)->assertJsonPath('data.can_checkout', true)->assertJsonPath('data.items.0.quantity_available', 1)->assertJsonPath('data.items.0.quantity_requested', 1)->assertJsonPath('data.items.0.line_total.millimes', 12_000);
    }

    public function test_quote_marks_zero_stock_for_removal_from_the_cart(): void
    {
        $product = $this->product(0);

        $this->postJson('/api/v1/public/cart/quote', ['items' => [['product_public_id' => $product->public_id, 'variant_public_id' => null, 'quantity' => 1]]])
            ->assertOk()->assertJsonPath('data.items.0.quantity_requested', 0)->assertJsonPath('data.items.0.is_available', false)->assertJsonPath('data.can_checkout', false);
    }

    public function test_quote_and_checkout_totals_stay_equal_for_the_same_basket(): void
    {
        $product = $this->product(3, 15_000, 10_000);
        $payload = $this->payload($product, 2);

        $quote = $this->postJson('/api/v1/public/cart/quote', ['items' => $payload['items']])->assertOk();
        $order = $this->withHeader('Idempotency-Key', '4af95712-4d91-4c57-8d29-917324200055')->postJson('/api/v1/public/orders', $payload)->assertCreated();

        $this->assertSame($quote->json('data.pricing.total.millimes'), $order->json('data.order.pricing.total.millimes'));
        $this->assertSame($quote->json('data.pricing.shipping.fee.millimes'), $order->json('data.order.pricing.shipping_fee.millimes'));
    }

    public function test_quote_and_checkout_use_the_same_canonical_promotion_price(): void
    {
        $product = $this->product(3, 120_000, 100_000);
        $payload = $this->payload($product, 1);

        $this->postJson('/api/v1/public/cart/quote', ['items' => $payload['items']])
            ->assertOk()
            ->assertJsonPath('data.items.0.regular_unit_price.millimes', 120_000)
            ->assertJsonPath('data.items.0.effective_unit_price.millimes', 100_000)
            ->assertJsonPath('data.pricing.product_discount.millimes', 20_000)
            ->assertJsonPath('data.pricing.total.millimes', 100_000);

        $response = $this->withHeader('Idempotency-Key', '4af95712-4d91-4c57-8d29-917324200056')
            ->postJson('/api/v1/public/orders', $payload)
            ->assertCreated()
            ->assertJsonPath('data.order.pricing.subtotal.millimes', 100_000)
            ->assertJsonPath('data.order.pricing.product_discount.millimes', 20_000)
            ->assertJsonPath('data.order.pricing.total.millimes', 100_000);

        $this->assertDatabaseHas('orders', [
            'public_reference' => $response->json('data.order.public_reference'),
            'product_discount_millimes' => 20_000,
            'total_millimes' => 100_000,
        ]);
    }

    public function test_variant_product_requires_a_matching_active_variant(): void
    {
        $category = Category::query()->create(['name' => 'Visage', 'slug' => 'visage', 'is_active' => true]);
        $product = Product::query()->create(['category_id' => $category->id, 'name' => 'Sérum', 'slug' => 'serum', 'regular_price_millimes' => 12_000, 'stock_quantity' => null, 'is_active' => true, 'has_variants' => true, 'published_at' => now()]);
        $group = $product->optionGroups()->create(['name' => 'Format']);
        $value = $group->values()->create(['value' => '30 ml']);
        $variant = $product->variants()->create(['combination_key' => (string) $value->id, 'stock_quantity' => 2, 'is_active' => true]);
        $variant->values()->sync([$value->id]);

        $this->postJson('/api/v1/public/cart/quote', ['items' => [['product_public_id' => $product->public_id, 'variant_public_id' => null, 'quantity' => 1]]])->assertJsonPath('data.can_checkout', false);
        $this->postJson('/api/v1/public/cart/quote', ['items' => [['product_public_id' => $product->public_id, 'variant_public_id' => $variant->public_id, 'quantity' => 1]]])->assertJsonPath('data.can_checkout', true)->assertJsonPath('data.items.0.variant_label', 'Format: 30 ml');
    }

    private function product(int $stock, int $regular = 12_000, ?int $promo = null): Product
    {
        $category = Category::query()->create(['name' => 'Corps', 'slug' => 'corps-'.str()->random(6), 'is_active' => true]);

        return Product::query()->create(['category_id' => $category->id, 'name' => 'Baume', 'slug' => 'baume-'.str()->random(6), 'regular_price_millimes' => $regular, 'promotional_price_millimes' => $promo, 'stock_quantity' => $stock, 'is_active' => true, 'has_variants' => false, 'published_at' => now()]);
    }

    /** @return array<string, mixed> */
    private function payload(Product $product, int $quantity = 2): array
    {
        $fields = CheckoutField::query()->where('is_active', true)->orderBy('sort_order')->get()->map(fn (CheckoutField $field) => $field->only(['key', 'label', 'type', 'is_required', 'options', 'sort_order']))->all();

        return ['checkout_schema_version' => app(CreateGuestOrderAction::class)->schemaVersion($fields), 'customer' => ['full_name' => 'Client Test', 'phone' => '22 123 456', 'city' => 'Tunis', 'governorate' => 'Tunis', 'address' => '10 rue de la Paix'], 'items' => [['product_public_id' => $product->public_id, 'variant_public_id' => null, 'quantity' => $quantity]]];
    }
}
