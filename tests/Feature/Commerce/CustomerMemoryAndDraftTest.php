<?php

namespace Tests\Feature\Commerce;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductImage;
use App\Domain\Commerce\Actions\PermanentlyDeleteArchivedOrdersAction;
use App\Domain\Commerce\Models\CheckoutDraft;
use App\Domain\Commerce\Models\CheckoutField;
use App\Domain\Commerce\Models\Customer;
use App\Domain\Commerce\Models\Order;
use App\Domain\MetaTracking\Models\MetaConfiguration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class CustomerMemoryAndDraftTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    public function test_phone_formats_share_one_customer_profile_and_lookup_is_admin_only(): void
    {
        $this->getJson('/api/v1/admin/customers/lookup?phone=27123456')->assertUnauthorized();
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $product = $this->product();
        $payload = $this->manualPayload($product, '+216 27 123 456');

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/orders', $payload)->assertCreated();
        $payload['idempotency_key'] = (string) str()->uuid();
        $payload['customer']['phone'] = '27123456';
        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/orders', $payload)->assertCreated();

        $this->assertSame(1, Customer::query()->count());
        $second = Order::query()->latest('id')->firstOrFail();
        self::assertNotNull($second->customer_previous_order_at);
        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/customers/lookup?phone=00216%2027%20123%20456')
            ->assertOk()
            ->assertJsonPath('data.phone_normalized', '27123456')
            ->assertJsonPath('data.orders_count', 2);
    }

    public function test_admin_can_load_a_bounded_history_of_prior_orders_for_a_returning_customer(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $product = $this->product();
        $first = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/orders', $this->manualPayload($product, '27123456'))->assertCreated();
        $firstReference = $first->json('data.order.public_reference');

        $secondPayload = $this->manualPayload($product, '+216 27 123 456');
        $second = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/orders', $secondPayload)->assertCreated();
        $secondReference = $second->json('data.order.public_reference');

        $thirdPayload = $this->manualPayload($product, '27123456');
        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/orders', $thirdPayload)->assertCreated();

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/admin/orders/'.$secondReference.'/customer-history')->assertUnauthorized();

        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/orders/'.$secondReference.'/customer-history')
            ->assertOk()
            ->assertJsonPath('data.orders.0.number', 1)
            ->assertJsonPath('data.orders.0.public_reference', $firstReference)
            ->assertJsonPath('data.orders.0.status', 'nouvelle')
            ->assertJsonPath('data.orders.0.items.0.name', 'Huile')
            ->assertJsonPath('data.has_more', false)
            ->assertJsonCount(1, 'data.orders');

        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/orders')
            ->assertOk()
            ->assertJsonFragment(['public_reference' => $secondReference, 'is_returning_customer' => true]);
    }

    public function test_draft_is_first_party_recoverable_after_inactivity_without_order_side_effects(): void
    {
        $product = $this->product();
        $response = $this->postJson('/api/v1/public/checkout-drafts', [
            'customer' => ['full_name' => 'Client en cours', 'phone' => '27123456'],
            'items' => [['product_public_id' => $product->public_id, 'variant_public_id' => null, 'quantity' => 1]],
        ])->assertCreated();
        $token = $response->json('data.token');

        $this->assertDatabaseCount('checkout_drafts', 1);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('customers', 0);
        self::assertSame(5, $product->fresh()->stock_quantity);

        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/checkout-drafts')->assertOk()->assertJsonPath('data.total', 0);
        $draft = CheckoutDraft::query()->where('public_token', $token)->firstOrFail();
        $draft->update(['last_activity_at' => now()->subMinutes(15)]);
        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/checkout-drafts')->assertOk()->assertJsonPath('data.total', 1);

        $this->patchJson('/api/v1/public/checkout-drafts/'.$token, ['customer' => ['city' => 'Tunis']])->assertOk();
        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/checkout-drafts')->assertOk()->assertJsonPath('data.total', 0);
    }

    public function test_admin_can_delete_an_unconverted_draft_without_touching_orders_or_stock(): void
    {
        $product = $this->product();
        $draft = $this->postJson('/api/v1/public/checkout-drafts', [
            'customer' => ['full_name' => 'Client à supprimer', 'phone' => '27123456'],
            'items' => [['product_public_id' => $product->public_id, 'variant_public_id' => null, 'quantity' => 1]],
        ])->assertCreated()->json('data.token');
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $this->deleteJson('/api/v1/admin/checkout-drafts/'.$draft)->assertUnauthorized();
        $this->actingAs($admin, 'sanctum')->deleteJson('/api/v1/admin/checkout-drafts/'.$draft)
            ->assertOk()
            ->assertJsonPath('data.deleted', true);

        $this->assertDatabaseMissing('checkout_drafts', ['public_token' => $draft]);
        $this->assertDatabaseCount('orders', 0);
        self::assertSame(5, $product->fresh()->stock_quantity);
    }

    public function test_abandoned_draft_responses_include_the_product_thumbnail(): void
    {
        $product = $this->product();
        $image = ProductImage::query()->create([
            'product_id' => $product->id,
            'path' => 'products/draft-thumbnail.jpg',
            'processing_status' => 'ready',
            'is_primary' => true,
            'sort_order' => 0,
        ]);
        $token = $this->postJson('/api/v1/public/checkout-drafts', [
            'customer' => ['full_name' => 'Client en cours', 'phone' => '27123456'],
            'items' => [['product_public_id' => $product->public_id, 'variant_public_id' => null, 'quantity' => 1]],
        ])->assertCreated()->json('data.token');
        $draft = CheckoutDraft::query()->where('public_token', $token)->firstOrFail();
        $draft->update(['last_activity_at' => now()->subMinutes(15)]);
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/checkout-drafts')
            ->assertOk()
            ->assertJsonPath('data.data.0.items.0.image_url', $image->public_url);
        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/checkout-drafts/'.$token)
            ->assertOk()
            ->assertJsonPath('data.cart_snapshot.0.image_url', $image->public_url);
    }

    public function test_draft_conversion_reuses_manual_order_rules_and_is_idempotent(): void
    {
        MetaConfiguration::query()->create(['configuration_version' => 1, 'state' => 'active', 'tracking_enabled' => true, 'pixel_id' => '1234567890', 'capi_access_token_encrypted' => Crypt::encryptString('test-token'), 'activated_at' => now()]);
        $product = $this->product();
        collect([
            ['key' => 'echange', 'label' => 'Échange', 'type' => 'select', 'options' => ['Non', 'Oui'], 'is_required' => false, 'is_active' => true, 'is_system' => false, 'sort_order' => 10],
            ['key' => 'article', 'label' => 'Article à échanger', 'type' => 'text', 'options' => null, 'is_required' => false, 'is_active' => true, 'is_system' => false, 'sort_order' => 11],
            ['key' => 'nb_echange', 'label' => 'Nombre à échanger', 'type' => 'number', 'options' => null, 'is_required' => false, 'is_active' => true, 'is_system' => false, 'sort_order' => 12],
        ])->each(fn (array $field): mixed => CheckoutField::query()->create($field));
        $draft = $this->postJson('/api/v1/public/checkout-drafts', [
            'customer' => ['full_name' => 'Client récupéré', 'phone' => '27123456', 'city' => 'Tunis', 'governorate' => 'Tunis', 'address' => '10 rue de la Paix'],
            'checkout_data' => ['echange' => 'Oui', 'article' => 'Ancien flacon', 'nb_echange' => '2'],
            'items' => [['product_public_id' => $product->public_id, 'variant_public_id' => null, 'quantity' => 1]],
        ])->json('data.token');
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/checkout-drafts/'.$draft.'/convert', [
            'status' => 'tentative_1',
            'customer' => ['full_name' => 'Client récupéré', 'phone' => '27123456', 'city' => 'Tunis', 'governorate' => 'Tunis', 'address' => '10 rue de la Paix'],
            'items' => [['product_public_id' => $product->public_id, 'variant_public_id' => null, 'quantity' => 1]],
        ])->assertCreated();
        $reference = $response->json('data.order.public_reference');

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/checkout-drafts/'.$draft.'/convert', ['status' => 'tentative_1'])->assertCreated()->assertJsonPath('data.order.public_reference', $reference);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('meta_events', 1);
        $this->assertDatabaseHas('checkout_drafts', ['public_token' => $draft, 'order_id' => Order::query()->sole()->id]);
        self::assertSame(4, $product->fresh()->stock_quantity);
        $this->assertSame(1, Customer::query()->count());
        $this->assertDatabaseHas('meta_events', ['event_name' => 'Purchase', 'order_id' => Order::query()->sole()->id]);
        $this->assertDatabaseHas('orders', ['is_exchange' => 1, 'exchange_article_designation' => 'Ancien flacon', 'exchange_article_count' => 2]);
        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/orders/'.$reference)
            ->assertOk()
            ->assertJsonPath('data.order.status_history.0.to_status', 'brouillon')
            ->assertJsonPath('data.order.status_history.0.changed_by.name', $admin->name)
            ->assertJsonPath('data.order.status_history.1.to_status', 'tentative_1')
            ->assertJsonPath('data.order.status_history.1.changed_by.name', $admin->name);
    }

    public function test_previous_order_snapshot_survives_deleting_the_previous_order(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $product = $this->product(5);
        $first = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/orders', $this->manualPayload($product, '27123456'))->assertCreated();
        $firstOrder = Order::query()->where('public_reference', $first->json('data.order.public_reference'))->firstOrFail();

        $secondPayload = $this->manualPayload($product, '+21627123456');
        $second = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/orders', $secondPayload)->assertCreated();
        $secondOrder = Order::query()->where('public_reference', $second->json('data.order.public_reference'))->firstOrFail();
        $previous = $secondOrder->customer_previous_order_at;

        $firstOrder->update(['archived_at' => now()]);
        app(PermanentlyDeleteArchivedOrdersAction::class)->handle([$firstOrder->public_reference], $admin);

        self::assertNotNull($previous);
        self::assertTrue($secondOrder->fresh()->customer_previous_order_at?->equalTo($previous));
        self::assertSame(2, Customer::query()->sole()->orders_count);
    }

    public function test_successful_customer_checkout_converts_its_draft_atomically(): void
    {
        $product = $this->product();
        $draft = $this->postJson('/api/v1/public/checkout-drafts', [
            'customer' => ['full_name' => 'Client final', 'phone' => '27123456', 'city' => 'Tunis', 'governorate' => 'Tunis', 'address' => '10 rue de la Paix'],
            'items' => [['product_public_id' => $product->public_id, 'variant_public_id' => null, 'quantity' => 1]],
        ])->assertCreated()->json('data.token');

        $schema = $this->getJson('/api/v1/public/checkout-fields')->json('meta.schema_version');
        $this->withHeader('Idempotency-Key', (string) str()->uuid())->postJson('/api/v1/public/orders', [
            'checkout_schema_version' => $schema,
            'draft_token' => $draft,
            'customer' => ['full_name' => 'Client final', 'phone' => '27123456', 'city' => 'Tunis', 'governorate' => 'Tunis', 'address' => '10 rue de la Paix'],
            'items' => [['product_public_id' => $product->public_id, 'variant_public_id' => null, 'quantity' => 1]],
        ])->assertCreated();

        $this->assertDatabaseCount('orders', 1);
        self::assertNotNull(CheckoutDraft::query()->where('public_token', $draft)->firstOrFail()->converted_at);
        $this->assertDatabaseCount('customers', 1);
    }

    /** @return array<string, mixed> */
    private function manualPayload(Product $product, string $phone): array
    {
        return ['idempotency_key' => (string) str()->uuid(), 'checkout_schema_version' => $this->getJson('/api/v1/public/checkout-fields')->json('meta.schema_version'), 'customer' => ['full_name' => 'Client', 'phone' => $phone, 'city' => 'Tunis', 'governorate' => 'Tunis', 'address' => '10 rue de la Paix'], 'items' => [['product_public_id' => $product->public_id, 'variant_public_id' => null, 'quantity' => 1]], 'status' => 'nouvelle'];
    }

    private function product(): Product
    {
        $category = Category::query()->create(['name' => 'Soin', 'slug' => 'soin-'.str()->random(6), 'is_active' => true]);

        return Product::query()->create(['category_id' => $category->id, 'name' => 'Huile', 'slug' => 'huile-'.str()->random(6), 'meta_catalog_id' => 'catalogue-huile', 'regular_price_millimes' => 15_000, 'stock_quantity' => 5, 'is_active' => true, 'has_variants' => false, 'published_at' => now()]);
    }
}
