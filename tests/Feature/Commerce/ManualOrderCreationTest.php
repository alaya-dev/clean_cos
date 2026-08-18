<?php

namespace Tests\Feature\Commerce;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Commerce\Models\Order;
use App\Domain\MetaTracking\Models\MetaConfiguration;
use App\Domain\MetaTracking\Models\MetaEvent;
use App\Domain\MetaTracking\Services\MetaConversionsClient;
use App\Domain\Navex\Models\NavexConfiguration;
use App\Jobs\CreateNavexShipmentJob;
use App\Jobs\DispatchMetaEventJob;
use App\Jobs\SendMetaEventJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ManualOrderCreationTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_admin_can_create_a_manual_order_with_authoritative_stock_and_a_server_only_purchase(): void
    {
        Queue::fake();
        MetaConfiguration::query()->create([
            'configuration_version' => 1,
            'state' => 'active',
            'tracking_enabled' => true,
            'pixel_id' => '1234567890',
            'capi_access_token_encrypted' => Crypt::encryptString('test-token'),
            'activated_at' => now(),
        ]);
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $product = $this->product(4);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/admin/orders', $this->payload($product, 2))
            ->assertCreated();

        $order = Order::query()->firstOrFail();
        $this->assertSame('nouvelle', $order->status);
        $this->assertSame(2, $product->fresh()->stock_quantity);
        $this->assertSame(30_000, $order->subtotal_millimes);
        $this->assertDatabaseHas('inventory_movements', ['product_id' => $product->id, 'actor_user_id' => $admin->id, 'type' => 'order_deduction', 'quantity_delta' => -2]);
        $this->assertDatabaseHas('order_status_history', ['order_id' => $order->id, 'to_status' => 'nouvelle', 'changed_by' => $admin->id]);

        $event = MetaEvent::query()->where('order_id', $order->id)->where('event_name', 'Purchase')->sole();
        $userData = json_decode(Crypt::decryptString((string) $event->user_data_encrypted), true, flags: JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('ph', $userData);
        $this->assertArrayNotHasKey('client_ip_address', $userData);
        $this->assertArrayNotHasKey('client_user_agent', $userData);
        $this->assertSame('website', $event->context_summary['action_source']);
        $this->assertSame(rtrim((string) config('app.url'), '/').'/', $event->source_url);
        Queue::assertPushed(DispatchMetaEventJob::class, fn (DispatchMetaEventJob $job): bool => $job->eventPublicId === $event->public_id);
        $response->assertJsonPath('data.order.public_reference', $order->public_reference);
    }

    public function test_manual_order_uses_the_trusted_operator_consent_path_for_every_entry(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $product = $this->product(2);
        MetaConfiguration::query()->create(['configuration_version' => 1, 'state' => 'active', 'tracking_enabled' => true, 'pixel_id' => '1234567890', 'capi_access_token_encrypted' => Crypt::encryptString('test-token'), 'activated_at' => now()]);

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/orders', $this->payload($product))->assertCreated();

        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseHas('meta_events', ['event_name' => 'Purchase']);
    }

    public function test_manual_order_can_start_with_an_operator_defined_total(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $product = $this->product(4);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/admin/orders', array_replace($this->payload($product), ['manual_total_millimes' => 17_500]))
            ->assertCreated();

        $order = Order::query()->findOrFail($response->json('data.order.id'));
        self::assertSame(17_500, $order->manual_total_millimes);
        self::assertSame(17_500, $order->total_millimes);
    }

    public function test_manual_order_rejects_an_invalid_operator_defined_total(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $product = $this->product(2);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/admin/orders', array_replace($this->payload($product), ['manual_total_millimes' => -1]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['manual_total_millimes']);
    }

    public function test_manual_order_defaults_to_no_exchange_and_validates_exchange_details(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $product = $this->product(4);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/admin/orders', array_replace($this->payload($product), ['exchange' => ['is_exchange' => 'Oui']]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['exchange.article_designation', 'exchange.article_count']);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/admin/orders', array_replace($this->payload($product), ['exchange' => ['is_exchange' => 'Oui', 'article_designation' => 'Ancien flacon', 'article_count' => 0]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['exchange.article_count']);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/admin/orders', $this->payload($product))
            ->assertCreated();

        $order = Order::query()->findOrFail($response->json('data.order.id'));
        self::assertFalse($order->is_exchange);
        self::assertNull($order->exchange_article_designation);
        self::assertNull($order->exchange_article_count);
    }

    public function test_manual_exchange_values_persist_and_are_returned_in_order_details(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $product = $this->product(4);
        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/admin/orders', array_replace($this->payload($product), ['exchange' => ['is_exchange' => 'Oui', 'article_designation' => 'Ancien produit', 'article_count' => 2]]))
            ->assertCreated();

        $reference = $response->json('data.order.public_reference');
        $this->assertDatabaseHas('orders', ['is_exchange' => 1, 'exchange_article_designation' => 'Ancien produit', 'exchange_article_count' => 2]);
        $this->actingAs($admin)->getJson('/api/v1/admin/orders/'.$reference)
            ->assertOk()
            ->assertJsonPath('data.order.is_exchange', true)
            ->assertJsonPath('data.order.exchange_article_designation', 'Ancien produit')
            ->assertJsonPath('data.order.exchange_article_count', 2);
    }

    public function test_manual_purchase_is_delivered_as_a_server_only_meta_event(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $product = $this->product(2);
        MetaConfiguration::query()->create(['configuration_version' => 1, 'state' => 'active', 'tracking_enabled' => true, 'pixel_id' => '1234567890', 'capi_access_token_encrypted' => Crypt::encryptString('test-token'), 'activated_at' => now()]);

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/orders', $this->payload($product))->assertCreated();
        $event = MetaEvent::query()->where('event_name', 'Purchase')->sole();
        (new DispatchMetaEventJob($event->public_id))->handle();
        Queue::assertPushed(SendMetaEventJob::class, fn (SendMetaEventJob $job): bool => $job->eventPublicId === $event->public_id);
        Http::fake(['graph.facebook.com/*' => Http::response(['events_received' => 1], 200)]);

        (new SendMetaEventJob($event->public_id))->handle(app(MetaConversionsClient::class));

        $event->refresh();
        self::assertSame('succeeded', $event->capi_state);
        Http::assertSent(function ($request): bool {
            $payload = $request->data()['data'][0];

            return $payload['event_name'] === 'Purchase'
                && $payload['action_source'] === 'website'
                && $payload['event_source_url'] === rtrim((string) config('app.url'), '/').'/'
                && isset($payload['user_data']['ph'])
                && ! isset($payload['user_data']['client_ip_address'])
                && ! isset($payload['user_data']['client_user_agent']);
        });
    }

    public function test_confirmed_manual_order_queues_meta_and_navex_after_the_order_commits(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $product = $this->product(2);
        MetaConfiguration::query()->create(['configuration_version' => 1, 'state' => 'active', 'tracking_enabled' => true, 'pixel_id' => '1234567890', 'capi_access_token_encrypted' => Crypt::encryptString('test-token'), 'activated_at' => now()]);
        NavexConfiguration::query()->create([
            'mode' => 'automatic',
            'api_base_url' => 'https://app.navex.tn',
            'creation_credential_encrypted' => Crypt::encryptString('creation-token'),
            'tracking_credential_encrypted' => Crypt::encryptString('tracking-token'),
            'deletion_credential_encrypted' => Crypt::encryptString('deletion-token'),
            'sender_name' => 'Passion Cosmetic',
            'sender_location' => 'Tunis',
            'sender_governorate' => 'Tunis',
            'parcel_opening_option' => 'Non',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/admin/orders', array_replace($this->payload($product), ['status' => 'confirmee']))
            ->assertCreated();

        $order = Order::query()->sole();
        $event = MetaEvent::query()->where('order_id', $order->id)->where('event_name', 'Purchase')->sole();
        $shipment = $order->navexShipment()->sole();
        Queue::assertPushed(DispatchMetaEventJob::class, fn (DispatchMetaEventJob $job): bool => $job->eventPublicId === $event->public_id);
        Queue::assertPushed(CreateNavexShipmentJob::class, fn (CreateNavexShipmentJob $job): bool => $job->shipmentPublicId === $shipment->public_id);
    }

    public function test_repeating_a_manual_submission_with_the_same_key_does_not_create_a_second_order(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $product = $this->product(3);
        $payload = $this->payload($product);

        $first = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/orders', $payload)->assertCreated();
        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/orders', $payload)
            ->assertCreated()
            ->assertJsonPath('data.order.public_reference', $first->json('data.order.public_reference'));

        $this->assertDatabaseCount('orders', 1);
        $this->assertSame(2, $product->fresh()->stock_quantity);
    }

    public function test_manual_order_rejects_insufficient_stock_without_creating_a_partial_order(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $product = $this->product(1);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/admin/orders', $this->payload($product, 2))
            ->assertStatus(409)
            ->assertJsonPath('code', 'INSUFFICIENT_STOCK');

        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(1, $product->fresh()->stock_quantity);
    }

    public function test_a_non_admin_cannot_create_a_manual_order(): void
    {
        $product = $this->product(2);
        $user = User::factory()->create(['role' => 'admin', 'is_active' => false]);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/admin/orders', $this->payload($product))->assertForbidden();
        $this->assertDatabaseCount('orders', 0);
    }

    /** @return array<string, mixed> */
    private function payload(Product $product, int $quantity = 1): array
    {
        return [
            'idempotency_key' => (string) str()->uuid(),
            'checkout_schema_version' => $this->getJson('/api/v1/public/checkout-fields')->json('meta.schema_version'),
            'customer' => [
                'full_name' => 'Cliente téléphone',
                'phone' => '+216 22 123 456',
                'city' => 'Tunis',
                'governorate' => 'Tunis',
                'address' => '10 rue de la Paix',
            ],
            'items' => [[
                'product_public_id' => $product->public_id,
                'variant_public_id' => null,
                'quantity' => $quantity,
            ]],
            'status' => 'nouvelle',
        ];
    }

    private function product(int $stock): Product
    {
        $category = Category::query()->create(['name' => 'Soin', 'slug' => 'soin-'.str()->random(6), 'is_active' => true]);

        return Product::query()->create([
            'category_id' => $category->id,
            'name' => 'Huile',
            'slug' => 'huile-'.str()->random(6),
            'meta_catalog_id' => 'catalogue-huile',
            'regular_price_millimes' => 15_000,
            'stock_quantity' => $stock,
            'is_active' => true,
            'has_variants' => false,
            'published_at' => now(),
        ]);
    }
}
