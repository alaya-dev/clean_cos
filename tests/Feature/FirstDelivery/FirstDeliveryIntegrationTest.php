<?php

namespace Tests\Feature\FirstDelivery;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Commerce\Actions\PermanentlyDeleteArchivedOrdersAction;
use App\Domain\Commerce\Actions\TransitionOrderStatusAction;
use App\Domain\Commerce\Models\Order;
use App\Domain\FirstDelivery\Enums\FirstDeliveryStatus;
use App\Domain\FirstDelivery\Models\FirstDeliveryConfiguration;
use App\Domain\FirstDelivery\Models\FirstDeliveryLocality;
use App\Domain\FirstDelivery\Models\FirstDeliveryShipment;
use App\Domain\FirstDelivery\Services\FirstDeliveryClient;
use App\Domain\FirstDelivery\Services\FirstDeliveryShipmentAttemptRecorder;
use App\Domain\FirstDelivery\Services\FirstDeliveryShipmentPayloadFactory;
use App\Domain\FirstDelivery\Services\FirstDeliveryShipmentService;
use App\Domain\FirstDelivery\Services\FirstDeliveryShipmentStateService;
use App\Domain\Navex\Enums\NavexDeliveryStatus;
use App\Domain\Navex\Models\NavexShipment;
use App\Domain\Navex\Services\NavexShipmentService;
use App\Jobs\CancelFirstDeliveryShipmentJob;
use App\Jobs\CreateFirstDeliveryShipmentJob;
use App\Jobs\SynchronizeFirstDeliveryShipmentJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FirstDeliveryIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    public function test_configuration_encrypts_and_masks_the_token_and_blank_updates_preserve_it(): void
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $response = $this->actingAs($superAdmin)->postJson('/api/v1/admin/first-delivery/configuration', [
            'mode' => 'manual',
            'api_base_url' => 'https://www.firstdeliverygroup.com/api/v2',
            'first_delivery_token' => 'first-delivery-secret',
        ])->assertOk()
            ->assertJsonPath('data.configuration.token_configured', true)
            ->assertJsonPath('data.configuration.token_masked', '••••••••••••••••••••')
            ->assertJsonMissing(['first-delivery-secret']);

        $configuration = FirstDeliveryConfiguration::query()->firstOrFail();
        self::assertSame('first-delivery-secret', Crypt::decryptString($configuration->token_encrypted));
        self::assertStringNotContainsString('first-delivery-secret', $response->getContent());

        $this->actingAs($superAdmin)->postJson('/api/v1/admin/first-delivery/configuration', [
            'mode' => 'automatic',
            'api_base_url' => 'https://www.firstdeliverygroup.com/api/v2',
            'first_delivery_token' => '',
        ])->assertOk()->assertJsonMissing(['first-delivery-secret']);

        self::assertSame('first-delivery-secret', Crypt::decryptString($configuration->fresh()->token_encrypted));

        $this->actingAs($superAdmin)->deleteJson('/api/v1/admin/first-delivery/configuration/token', [
            'confirm_removal' => true,
        ])->assertOk()
            ->assertJsonPath('data.configuration.mode', 'disabled')
            ->assertJsonPath('data.configuration.token_configured', false);

        self::assertNull($configuration->fresh()->token_encrypted);
    }

    public function test_configuration_endpoints_require_authentication_and_store_management_permission(): void
    {
        $this->getJson('/api/v1/admin/first-delivery/configuration')->assertUnauthorized();

        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->actingAs($admin)->postJson('/api/v1/admin/first-delivery/configuration', [
            'mode' => 'manual',
            'api_base_url' => 'https://www.firstdeliverygroup.com/api/v2',
            'first_delivery_token' => 'secret',
        ])->assertForbidden();
    }

    public function test_connection_test_uses_the_token_and_synchronizes_provider_localities(): void
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $configuration = $this->configuration('manual');
        Http::fake([
            'https://www.firstdeliverygroup.com/api/v2/localities' => Http::response([
                'isError' => false,
                'message' => 'OK',
                'result' => [
                    ['locality_id' => 101, 'locality_name' => 'Tunis', 'delegation_name' => 'Tunis Ville', 'governorate_name' => 'Tunis'],
                    ['locality_id' => 102, 'locality_name' => 'La Marsa', 'delegation_name' => 'La Marsa', 'governorate_name' => 'Tunis'],
                ],
            ]),
        ]);

        $this->actingAs($superAdmin)
            ->postJson("/api/v1/admin/first-delivery/configuration/{$configuration->public_id}/test")
            ->assertOk()
            ->assertJsonPath('data.test_result.status', 'connected')
            ->assertJsonPath('data.test_result.localities_count', 2)
            ->assertJsonMissing(['first-delivery-secret']);

        self::assertDatabaseHas('first_delivery_localities', [
            'locality_id' => 101,
            'delegation_name' => 'Tunis Ville',
        ]);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://www.firstdeliverygroup.com/api/v2/localities'
            && $request->hasHeader('Authorization', 'Bearer first-delivery-secret')
            && $request->hasHeader('Accept', 'application/json'));
    }

    public function test_client_uses_documented_paths_json_shapes_and_never_puts_token_in_the_url(): void
    {
        $configuration = $this->configuration('manual');
        Http::fake([
            'https://www.firstdeliverygroup.com/api/v2/*' => Http::response([
                'isError' => false,
                'result' => ['barCode' => '123456789012', 'link' => 'https://www.firstdeliverygroup.com/print/123456789012', 'state' => 0],
            ]),
        ]);
        $payload = [
            'Client' => ['nom' => 'Client test', 'locality_id' => 101],
            'Produit' => ['prix' => 79.9, 'designation' => '1 Crème', 'nombreArticle' => 1],
        ];

        $client = app(FirstDeliveryClient::class);
        self::assertTrue($client->createOrder($configuration, $payload)->accepted);
        $client->getOrderStatus($configuration, '123456789012');
        $client->cancelOrder($configuration, '123456789012');

        Http::assertSent(function (Request $request) use ($payload): bool {
            self::assertStringNotContainsString('first-delivery-secret', $request->url());
            self::assertTrue($request->hasHeader('Authorization', 'Bearer first-delivery-secret'));

            return match ($request->url()) {
                'https://www.firstdeliverygroup.com/api/v2/create' => $request->method() === 'POST' && $request->data() === $payload,
                'https://www.firstdeliverygroup.com/api/v2/etat' => $request['barCode'] === '123456789012',
                'https://www.firstdeliverygroup.com/api/v2/cancel-orders' => $request['barCodes'] === ['123456789012'],
                default => false,
            };
        });
        Http::assertSentCount(3);
    }

    public function test_payload_is_built_from_authoritative_order_values_and_selected_locality(): void
    {
        $locality = $this->locality();
        $order = $this->order('confirmee', $locality);
        $order->update([
            'is_exchange' => true,
            'exchange_article_designation' => 'Ancien article',
            'exchange_article_count' => 2,
        ]);

        $payload = app(FirstDeliveryShipmentPayloadFactory::class)->make($order->load('items'), $locality);

        self::assertSame(101, $payload['Client']['locality_id']);
        self::assertSame('22123456', $payload['Client']['telephone']);
        self::assertSame(79.9, $payload['Produit']['prix']);
        self::assertSame(1, $payload['Produit']['nombreArticle']);
        self::assertSame(2, $payload['Produit']['nombreEchange']);
        self::assertSame('non', $payload['Produit']['estFragile']);
        self::assertSame('non', $payload['Produit']['ouvrirColis']);
    }

    public function test_manual_queue_is_durable_idempotent_and_requires_a_confirmed_order_with_locality(): void
    {
        Queue::fake();
        $this->configuration('manual');
        $locality = $this->locality();
        $order = $this->order('confirmee', $locality);

        $shipment = app(FirstDeliveryShipmentService::class)->queue($order, 'manual');

        self::assertSame(FirstDeliveryStatus::PendingSend, $shipment->local_status);
        self::assertNull($shipment->barcode);
        self::assertNotNull($shipment->request_snapshot_encrypted);
        self::assertDatabaseHas('first_delivery_shipment_status_history', [
            'first_delivery_shipment_id' => $shipment->id,
            'local_status' => FirstDeliveryStatus::PendingSend->value,
        ]);
        Queue::assertPushed(CreateFirstDeliveryShipmentJob::class, fn (CreateFirstDeliveryShipmentJob $job): bool => $job->shipmentPublicId === $shipment->public_id);

        try {
            app(FirstDeliveryShipmentService::class)->queue($order->fresh(), 'manual');
            self::fail('A duplicate shipment should have been rejected.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('shipment', $exception->errors());
        }
        self::assertDatabaseCount('first_delivery_shipments', 1);
    }

    public function test_navex_and_first_delivery_shipments_are_mutually_exclusive(): void
    {
        Queue::fake();
        $this->configuration('manual');
        $locality = $this->locality();

        $navexOrder = $this->order('confirmee', $locality);
        NavexShipment::query()->create([
            'order_id' => $navexOrder->id,
            'status' => NavexDeliveryStatus::PendingSend,
            'creation_mode' => 'manual',
        ]);
        try {
            app(FirstDeliveryShipmentService::class)->queue($navexOrder, 'manual');
            self::fail('A First Delivery shipment should be blocked when Navex exists.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('provider', $exception->errors());
        }

        $firstOrder = $this->order('confirmee', $locality);
        app(FirstDeliveryShipmentService::class)->queue($firstOrder, 'manual');
        $ready = app(NavexShipmentService::class)->ready($firstOrder->fresh());
        self::assertFalse($ready['ready']);
        self::assertContains('Une expédition First Delivery existe déjà.', $ready['reasons']);
    }

    public function test_creation_job_persists_barcode_safe_print_link_history_and_sanitized_attempt(): void
    {
        Queue::fake();
        $this->configuration('manual');
        $shipment = app(FirstDeliveryShipmentService::class)->queue($this->order('confirmee', $this->locality()), 'manual');
        Http::fake([
            'https://www.firstdeliverygroup.com/api/v2/create' => Http::response([
                'isError' => false,
                'message' => 'Colis créé',
                'result' => [
                    'barCode' => '123456789012',
                    'link' => 'https://www.firstdeliverygroup.com/print/123456789012',
                ],
            ], 201),
        ]);

        (new CreateFirstDeliveryShipmentJob($shipment->public_id))->handle(
            app(FirstDeliveryClient::class),
            app(FirstDeliveryShipmentAttemptRecorder::class),
            app(FirstDeliveryShipmentStateService::class),
        );

        $shipment = $shipment->fresh();
        self::assertNotNull($shipment);
        self::assertSame(FirstDeliveryStatus::Accepted, $shipment->local_status);
        self::assertSame('123456789012', $shipment->barcode);
        self::assertSame('https://www.firstdeliverygroup.com/print/123456789012', $shipment->print_url);
        self::assertDatabaseHas('first_delivery_shipment_attempts', [
            'first_delivery_shipment_id' => $shipment->id,
            'operation' => 'creation',
            'request_sent' => true,
            'outcome' => 'accepted',
        ]);
        Queue::assertPushed(SynchronizeFirstDeliveryShipmentJob::class);
    }

    public function test_uncertain_creation_stops_automatic_retries_to_prevent_provider_duplicates(): void
    {
        Queue::fake();
        $this->configuration('manual');
        $shipment = app(FirstDeliveryShipmentService::class)->queue($this->order('confirmee', $this->locality()), 'manual');
        Http::fake(['https://www.firstdeliverygroup.com/api/v2/create' => Http::failedConnection()]);

        (new CreateFirstDeliveryShipmentJob($shipment->public_id))->handle(
            app(FirstDeliveryClient::class),
            app(FirstDeliveryShipmentAttemptRecorder::class),
            app(FirstDeliveryShipmentStateService::class),
        );

        $shipment = $shipment->fresh();
        self::assertSame(FirstDeliveryStatus::UncertainResult, $shipment->local_status);
        self::assertSame('network_uncertain', $shipment->last_error);
        self::assertNull($shipment->barcode);
        self::assertDatabaseCount('first_delivery_shipments', 1);
    }

    public function test_http_provider_creation_errors_are_failed_provider_errors_not_uncertain_results(): void
    {
        Queue::fake();
        $this->configuration('manual');
        $shipment = app(FirstDeliveryShipmentService::class)->queue($this->order('confirmee', $this->locality()), 'manual');
        Http::fake(['https://www.firstdeliverygroup.com/api/v2/create' => Http::response([
            'isError' => true,
            'message' => 'Invalid locality',
        ], 422)]);

        (new CreateFirstDeliveryShipmentJob($shipment->public_id))->handle(
            app(FirstDeliveryClient::class),
            app(FirstDeliveryShipmentAttemptRecorder::class),
            app(FirstDeliveryShipmentStateService::class),
        );

        $shipment = $shipment->fresh();
        self::assertSame(FirstDeliveryStatus::SynchronizationError, $shipment->local_status);
        self::assertSame('provider_error', $shipment->last_error);
        self::assertDatabaseHas('first_delivery_shipment_attempts', [
            'first_delivery_shipment_id' => $shipment->id,
            'http_status' => 422,
            'request_sent' => true,
            'outcome' => 'provider_error',
            'error_classification' => 'provider_error',
        ]);
        Queue::assertPushed(CreateFirstDeliveryShipmentJob::class, 1);
    }

    public function test_provider_status_codes_are_mapped_without_mutating_the_order_business_status(): void
    {
        Queue::fake();
        $this->configuration('manual');
        $order = $this->order('confirmee', $this->locality());
        $shipment = app(FirstDeliveryShipmentService::class)->queue($order, 'manual');
        $states = app(FirstDeliveryShipmentStateService::class);
        $expected = [
            0 => FirstDeliveryStatus::Pending,
            1 => FirstDeliveryStatus::InProgress,
            2 => FirstDeliveryStatus::Delivered,
            3 => FirstDeliveryStatus::Exchange,
            5 => FirstDeliveryStatus::ReturnedToSender,
            6 => FirstDeliveryStatus::Cancelled,
            20 => FirstDeliveryStatus::Verify,
            31 => FirstDeliveryStatus::FinalReturn,
            100 => FirstDeliveryStatus::PickupRequested,
            204 => FirstDeliveryStatus::ReturnCancelled,
        ];

        foreach ($expected as $code => $status) {
            $shipment = $states->synchronize($shipment, ['state' => $code, 'barCode' => '123456789012']);
            self::assertSame($status, $shipment->local_status);
        }

        self::assertSame('confirmee', $order->fresh()->status);
    }

    public function test_cancellation_is_allowed_only_for_pending_provider_shipments_and_is_confirmed_by_job(): void
    {
        Queue::fake();
        $superAdmin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $configuration = $this->configuration('manual');
        $order = $this->order('confirmee', $this->locality());
        $shipment = FirstDeliveryShipment::query()->create([
            'order_id' => $order->id,
            'first_delivery_configuration_id' => $configuration->id,
            'locality_id' => 101,
            'local_status' => FirstDeliveryStatus::Pending,
            'barcode' => '123456789012',
            'remote_state_code' => 0,
            'remote_state' => 'En attente',
            'creation_mode' => 'manual',
        ]);

        $this->actingAs($superAdmin)->postJson("/api/v1/admin/orders/{$order->public_reference}/first-delivery/cancel", [
            'confirm_cancellation' => true,
        ])->assertOk()->assertJsonPath('data.shipment.status', FirstDeliveryStatus::CancellationPending->value);

        Queue::assertPushed(CancelFirstDeliveryShipmentJob::class);
        Http::fake([
            'https://www.firstdeliverygroup.com/api/v2/cancel-orders' => Http::response([
                'isError' => false,
                'result' => ['123456789012'],
            ]),
        ]);
        (new CancelFirstDeliveryShipmentJob($shipment->public_id))->handle(
            app(FirstDeliveryClient::class),
            app(FirstDeliveryShipmentAttemptRecorder::class),
            app(FirstDeliveryShipmentStateService::class),
        );

        self::assertSame(FirstDeliveryStatus::Cancelled, $shipment->fresh()->local_status);
        self::assertNotNull($shipment->fresh()->cancelled_at);
    }

    public function test_automatic_mode_queues_first_delivery_after_confirmation_and_order_apis_identify_provider(): void
    {
        Queue::fake();
        $this->configuration('automatic');
        $locality = $this->locality();
        $order = $this->order('nouvelle', $locality);
        $actor = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        app(TransitionOrderStatusAction::class)->handle($order, 'confirmee', null, $actor->id);

        $shipment = FirstDeliveryShipment::query()->where('order_id', $order->id)->firstOrFail();
        self::assertSame(FirstDeliveryStatus::PendingSend, $shipment->local_status);
        Queue::assertPushed(CreateFirstDeliveryShipmentJob::class);

        $superAdmin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $this->actingAs($superAdmin)
            ->getJson("/api/v1/admin/orders/{$order->public_reference}")
            ->assertOk()
            ->assertJsonPath('data.first_delivery.shipment.provider', 'first_delivery')
            ->assertJsonPath('data.first_delivery.shipment.status', FirstDeliveryStatus::PendingSend->value)
            ->assertJsonMissingPath('data.first_delivery.shipment.request_snapshot_encrypted');

        $this->actingAs($superAdmin)
            ->getJson('/api/v1/admin/orders?delivery_provider=first_delivery')
            ->assertOk()
            ->assertJsonPath('data.data.0.delivery.provider', 'first_delivery')
            ->assertJsonPath('data.data.0.delivery.provider_label', 'First Delivery');
    }

    public function test_archived_order_with_terminal_first_delivery_shipment_can_be_permanently_deleted(): void
    {
        $configuration = $this->configuration('manual');
        $locality = $this->locality();
        $order = $this->order('annulee', $locality);
        $order->forceFill(['archived_at' => now()])->save();
        $shipment = FirstDeliveryShipment::query()->create([
            'order_id' => $order->id,
            'first_delivery_configuration_id' => $configuration->id,
            'locality_id' => $locality->locality_id,
            'local_status' => FirstDeliveryStatus::Cancelled,
            'barcode' => '123456789012',
            'remote_state_code' => 6,
            'creation_mode' => 'manual',
            'cancelled_at' => now(),
        ]);
        $actor = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $result = app(PermanentlyDeleteArchivedOrdersAction::class)->handle([$order->public_reference], $actor);

        self::assertSame(['deleted' => 1], $result);
        self::assertDatabaseMissing('orders', ['id' => $order->id]);
        self::assertDatabaseMissing('first_delivery_shipments', ['id' => $shipment->id]);
    }

    public function test_archived_order_with_active_first_delivery_shipment_cannot_be_permanently_deleted(): void
    {
        $configuration = $this->configuration('manual');
        $locality = $this->locality();
        $order = $this->order('confirmee', $locality);
        $order->forceFill(['archived_at' => now()])->save();
        $shipment = FirstDeliveryShipment::query()->create([
            'order_id' => $order->id,
            'first_delivery_configuration_id' => $configuration->id,
            'locality_id' => $locality->locality_id,
            'local_status' => FirstDeliveryStatus::Pending,
            'barcode' => '123456789012',
            'remote_state_code' => 0,
            'creation_mode' => 'manual',
        ]);
        $actor = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        try {
            app(PermanentlyDeleteArchivedOrdersAction::class)->handle([$order->public_reference], $actor);
            self::fail('La suppression devait être bloquée tant que le colis First Delivery est actif.');
        } catch (ValidationException $exception) {
            self::assertStringContainsString('encore active chez First Delivery', $exception->getMessage());
        }

        self::assertDatabaseHas('orders', ['id' => $order->id]);
        self::assertDatabaseHas('first_delivery_shipments', ['id' => $shipment->id]);
    }

    private function configuration(string $mode): FirstDeliveryConfiguration
    {
        return FirstDeliveryConfiguration::query()->create([
            'mode' => $mode,
            'api_base_url' => 'https://www.firstdeliverygroup.com/api/v2',
            'token_encrypted' => Crypt::encryptString('first-delivery-secret'),
        ]);
    }

    private function locality(): FirstDeliveryLocality
    {
        return FirstDeliveryLocality::query()->firstOrCreate(
            ['locality_id' => 101],
            [
                'locality_name' => 'Tunis',
                'delegation_name' => 'Tunis Ville',
                'governorate_name' => 'Tunis',
                'last_synced_at' => now(),
            ],
        );
    }

    private function order(string $status, FirstDeliveryLocality $locality): Order
    {
        $category = Category::query()->create([
            'name' => 'Soin',
            'slug' => 'soin-'.str()->random(8),
            'is_active' => true,
        ]);
        $product = Product::query()->create([
            'category_id' => $category->id,
            'name' => 'Crème',
            'slug' => 'creme-'.str()->random(8),
            'regular_price_millimes' => 79_900,
            'stock_quantity' => 10,
            'is_active' => true,
        ]);
        $order = Order::query()->create([
            'checkout_idempotency_key' => (string) str()->uuid(),
            'checkout_payload_hash' => str()->random(64),
            'status' => $status,
            'customer_name' => 'Client test',
            'customer_phone' => '22123456',
            'customer_city' => 'Tunis',
            'customer_governorate' => 'Tunis',
            'first_delivery_locality_id' => $locality->locality_id,
            'customer_address' => 'Rue de la paix',
            'subtotal_millimes' => 79_900,
            'product_discount_millimes' => 0,
            'promo_code_discount_millimes' => 0,
            'shipping_fee_millimes' => 0,
            'total_millimes' => 79_900,
        ]);
        $order->items()->create([
            'product_id' => $product->id,
            'product_name_snapshot' => $product->name,
            'regular_unit_price_millimes' => 79_900,
            'effective_unit_price_millimes' => 79_900,
            'quantity' => 1,
            'line_total_millimes' => 79_900,
        ]);

        return $order;
    }
}
