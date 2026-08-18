<?php

namespace Tests\Integration\Commerce;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Checkout\Actions\PruneExpiredCheckoutIdempotencyRecordsAction;
use App\Domain\Commerce\Actions\CreateGuestOrderAction;
use App\Domain\Commerce\Models\CheckoutField;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

class CheckoutIdempotencyRetentionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    public function test_idempotent_replay_and_retention_cleanup_leave_order_intact(): void
    {
        $product = $this->product(3);
        $payload = $this->payload($product);
        $key = '4af95712-4d91-4c57-8d29-917324201201';

        $first = $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/public/orders', $payload)->assertCreated();
        $this->assertDatabaseHas('checkout_idempotency_records', ['idempotency_key' => $key]);

        $this->travel(8)->days();
        app(PruneExpiredCheckoutIdempotencyRecordsAction::class)->handle();

        $this->assertDatabaseMissing('checkout_idempotency_records', ['idempotency_key' => $key]);
        $this->assertDatabaseHas('orders', ['public_reference' => $first->json('data.order.public_reference')]);
    }

    private function payload(Product $product): array
    {
        $fields = CheckoutField::query()->where('is_active', true)->orderBy('sort_order')->get()->map(fn (CheckoutField $field) => $field->only(['key', 'label', 'type', 'is_required', 'options', 'sort_order']))->all();

        return ['checkout_schema_version' => app(CreateGuestOrderAction::class)->schemaVersion($fields), 'customer' => ['full_name' => 'Client Test', 'phone' => '22 123 456', 'city' => 'Tunis', 'governorate' => 'Tunis', 'address' => '10 rue de la Paix'], 'items' => [['product_public_id' => $product->public_id, 'variant_public_id' => null, 'quantity' => 1]]];
    }

    private function product(int $stock): Product
    {
        $category = Category::query()->create(['name' => 'Cat', 'slug' => 'cat-'.str()->random(8), 'is_active' => true]);

        return Product::query()->create(['category_id' => $category->id, 'name' => 'Produit', 'slug' => 'produit-'.str()->random(8), 'regular_price_millimes' => 12_000, 'stock_quantity' => $stock, 'is_active' => true, 'has_variants' => false, 'published_at' => now()]);
    }
}
