<?php

namespace Tests\Feature\FirstDelivery;

use App\Domain\Commerce\Actions\PermanentlyDeleteArchivedOrdersAction;
use App\Domain\Commerce\Models\Order;
use App\Domain\FirstDelivery\Enums\FirstDeliveryPickupStatus;
use App\Domain\FirstDelivery\Enums\FirstDeliveryStatus;
use App\Domain\FirstDelivery\Models\FirstDeliveryConfiguration;
use App\Domain\FirstDelivery\Models\FirstDeliveryLocality;
use App\Domain\FirstDelivery\Models\FirstDeliveryPickup;
use App\Domain\FirstDelivery\Models\FirstDeliveryShipment;
use App\Domain\FirstDelivery\Services\FirstDeliveryClient;
use App\Domain\FirstDelivery\Services\FirstDeliveryPickupAttemptRecorder;
use App\Domain\FirstDelivery\Services\FirstDeliveryPickupService;
use App\Jobs\CreateFirstDeliveryPickupJob;
use App\Jobs\RefreshFirstDeliveryPickupPrintJob;
use App\Jobs\SynchronizeFirstDeliveryShipmentJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class FirstDeliveryPickupIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    public function test_client_uses_documented_pickup_and_print_endpoints_with_exact_payload(): void
    {
        $configuration = $this->configuration();
        Http::fake([
            'https://www.firstdeliverygroup.com/api/v2/pickup' => Http::response([
                'status' => 201, 'isError' => false, 'message' => 'OK',
                'result' => ['pickup' => '683375045049', 'link' => 'https://www.firstdeliverygroup.com/api/v2/print-pickup?q=safe'],
            ], 201),
            'https://www.firstdeliverygroup.com/api/v2/request-print/683375045049' => Http::response([
                'status' => 200, 'isError' => false, 'message' => 'OK',
                'result' => ['pickup' => '683375045049', 'link' => 'https://www.firstdeliverygroup.com/api/v2/print-pickup?q=fresh'],
            ]),
        ]);

        $created = app(FirstDeliveryClient::class)->createPickup($configuration, ['123456789012', '123456789013']);
        $printed = app(FirstDeliveryClient::class)->printPickup($configuration, '683375045049');

        self::assertTrue($created->accepted);
        self::assertSame('683375045049', $created->pickupId);
        self::assertSame('https://www.firstdeliverygroup.com/api/v2/print-pickup?q=fresh', $printed->printUrl);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://www.firstdeliverygroup.com/api/v2/pickup'
            && $request->method() === 'POST'
            && $request->data() === ['barCodes' => ['123456789012', '123456789013']]
            && $request->hasHeader('Authorization', 'Bearer pickup-secret'));
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://www.firstdeliverygroup.com/api/v2/request-print/683375045049'
            && $request->method() === 'POST'
            && $request->body() === '');
    }

    public function test_admin_can_queue_one_or_more_pending_shipments_once(): void
    {
        Queue::fake();
        $this->configuration();
        $first = $this->shipment('123456789012');
        $second = $this->shipment('123456789013');
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $this->actingAs($admin)->postJson('/api/v1/admin/first-delivery/pickups', [
            'shipment_public_ids' => [$first->public_id, $second->public_id],
            'confirm_creation' => true,
        ])->assertStatus(202)
            ->assertJsonPath('data.pickup.status', FirstDeliveryPickupStatus::Pending->value)
            ->assertJsonPath('data.pickup.shipment_count', 2)
            ->assertJsonMissing(['pickup-secret']);

        self::assertDatabaseCount('first_delivery_pickups', 1);
        self::assertDatabaseCount('first_delivery_pickup_items', 2);
        Queue::assertPushed(CreateFirstDeliveryPickupJob::class, 1);

        $this->actingAs($admin)->postJson('/api/v1/admin/first-delivery/pickups', [
            'shipment_public_ids' => [$first->public_id],
            'confirm_creation' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('shipment_public_ids');
        self::assertDatabaseCount('first_delivery_pickups', 1);
    }

    public function test_pickup_rejects_duplicate_non_pending_and_unauthenticated_selections(): void
    {
        $this->configuration();
        $shipment = $this->shipment('123456789012');
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $this->postJson('/api/v1/admin/first-delivery/pickups', [
            'shipment_public_ids' => [$shipment->public_id], 'confirm_creation' => true,
        ])->assertUnauthorized();

        $this->actingAs($admin)->postJson('/api/v1/admin/first-delivery/pickups', [
            'shipment_public_ids' => [$shipment->public_id, $shipment->public_id], 'confirm_creation' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('shipment_public_ids.1');

        $shipment->update(['remote_state_code' => 1, 'local_status' => FirstDeliveryStatus::InProgress]);
        $this->actingAs($admin)->postJson('/api/v1/admin/first-delivery/pickups', [
            'shipment_public_ids' => [$shipment->public_id], 'confirm_creation' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('shipment_public_ids');
    }

    public function test_creation_job_persists_pickup_and_dispatches_spaced_shipment_syncs(): void
    {
        Queue::fake();
        $this->configuration();
        $first = $this->shipment('123456789012');
        $second = $this->shipment('123456789013');
        $actor = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $pickup = app(FirstDeliveryPickupService::class)->queue([$first->public_id, $second->public_id], $actor);
        Http::fake(['https://www.firstdeliverygroup.com/api/v2/pickup' => Http::response([
            'status' => 201, 'isError' => false, 'message' => 'Créé',
            'result' => ['pickup' => '683375045049', 'link' => 'https://www.firstdeliverygroup.com/api/v2/print-pickup?q=safe'],
        ], 201)]);

        (new CreateFirstDeliveryPickupJob($pickup->public_id))->handle(
            app(FirstDeliveryClient::class),
            app(FirstDeliveryPickupAttemptRecorder::class),
        );

        $pickup->refresh();
        self::assertSame(FirstDeliveryPickupStatus::Created, $pickup->status);
        self::assertSame('683375045049', $pickup->provider_pickup_id);
        self::assertSame('https://www.firstdeliverygroup.com/api/v2/print-pickup?q=safe', $pickup->print_url);
        self::assertDatabaseHas('first_delivery_pickup_attempts', ['operation' => 'creation', 'outcome' => 'accepted']);
        Queue::assertPushed(SynchronizeFirstDeliveryShipmentJob::class, 2);
    }

    public function test_uncertain_pickup_is_never_automatically_sent_twice(): void
    {
        Queue::fake();
        $this->configuration();
        $shipment = $this->shipment('123456789012');
        $actor = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $pickup = app(FirstDeliveryPickupService::class)->queue([$shipment->public_id], $actor);
        Http::fake(['https://www.firstdeliverygroup.com/api/v2/pickup' => Http::failedConnection()]);
        $job = new CreateFirstDeliveryPickupJob($pickup->public_id);

        $job->handle(app(FirstDeliveryClient::class), app(FirstDeliveryPickupAttemptRecorder::class));
        $job->handle(app(FirstDeliveryClient::class), app(FirstDeliveryPickupAttemptRecorder::class));

        $freshPickup = $pickup->fresh();
        self::assertInstanceOf(FirstDeliveryPickup::class, $freshPickup);
        self::assertSame(FirstDeliveryPickupStatus::UncertainResult, $freshPickup->status);
        self::assertFalse($freshPickup->retryable);
        Http::assertSentCount(1);
        $this->actingAs($actor)->postJson("/api/v1/admin/first-delivery/pickups/{$pickup->public_id}/retry", [
            'confirm_retry' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('pickup');
    }

    public function test_explicit_provider_rejection_can_be_safely_retried(): void
    {
        Queue::fake();
        $this->configuration();
        $shipment = $this->shipment('123456789012');
        $actor = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $pickup = app(FirstDeliveryPickupService::class)->queue([$shipment->public_id], $actor);
        Http::fake(['https://www.firstdeliverygroup.com/api/v2/pickup' => Http::response([
            'status' => 422, 'isError' => true, 'message' => 'Colis refusé',
        ], 422)]);

        (new CreateFirstDeliveryPickupJob($pickup->public_id))->handle(
            app(FirstDeliveryClient::class), app(FirstDeliveryPickupAttemptRecorder::class),
        );
        $freshPickup = $pickup->fresh();
        self::assertInstanceOf(FirstDeliveryPickup::class, $freshPickup);
        self::assertSame(FirstDeliveryPickupStatus::Failed, $freshPickup->status);
        self::assertTrue($freshPickup->retryable);

        $this->actingAs($actor)->postJson("/api/v1/admin/first-delivery/pickups/{$pickup->public_id}/retry", [
            'confirm_retry' => true,
        ])->assertOk()->assertJsonPath('data.pickup.status', FirstDeliveryPickupStatus::Pending->value);
        Queue::assertPushed(CreateFirstDeliveryPickupJob::class, 2);
    }

    public function test_admin_can_refresh_a_missing_print_link_without_exposing_unsafe_urls(): void
    {
        Queue::fake();
        $configuration = $this->configuration();
        $pickup = FirstDeliveryPickup::query()->create([
            'first_delivery_configuration_id' => $configuration->id,
            'provider_pickup_id' => '683375045049',
            'status' => FirstDeliveryPickupStatus::Created,
            'shipment_count' => 1,
            'queued_at' => now(),
            'confirmed_at' => now(),
        ]);
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $this->actingAs($admin)->postJson("/api/v1/admin/first-delivery/pickups/{$pickup->public_id}/refresh-print", [
            'confirm_refresh' => true,
        ])->assertOk()->assertJsonPath('data.pickup.print_refresh_pending', true);
        Queue::assertPushed(RefreshFirstDeliveryPickupPrintJob::class);

        Http::fake(['https://www.firstdeliverygroup.com/api/v2/request-print/683375045049' => Http::response([
            'status' => 200, 'isError' => false, 'message' => 'OK',
            'result' => ['pickup' => '683375045049', 'link' => 'https://evil.example/steal'],
        ])]);
        (new RefreshFirstDeliveryPickupPrintJob($pickup->public_id))->handle(
            app(FirstDeliveryClient::class),
            app(FirstDeliveryPickupAttemptRecorder::class),
        );

        $freshPickup = $pickup->fresh();
        self::assertInstanceOf(FirstDeliveryPickup::class, $freshPickup);
        self::assertNull($freshPickup->print_url);
        self::assertSame('print_link_missing', $freshPickup->print_error);
        self::assertFalse($freshPickup->print_refresh_pending);
    }

    public function test_delivery_list_exposes_manifest_eligibility_and_relation(): void
    {
        $this->configuration();
        $shipment = $this->shipment('123456789012');
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $this->actingAs($admin)->getJson('/api/v1/admin/first-delivery/deliveries')
            ->assertOk()
            ->assertJsonPath('data.data.0.pickup_eligible', true)
            ->assertJsonPath('data.data.0.pickup', null);

        Queue::fake();
        app(FirstDeliveryPickupService::class)->queue([$shipment->public_id], $admin);
        $this->actingAs($admin)->getJson('/api/v1/admin/first-delivery/deliveries')
            ->assertOk()
            ->assertJsonPath('data.data.0.pickup_eligible', false)
            ->assertJsonPath('data.data.0.pickup.status', FirstDeliveryPickupStatus::Pending->value);
    }

    public function test_manifest_item_snapshots_survive_terminal_order_deletion(): void
    {
        Queue::fake();
        $this->configuration();
        $shipment = $this->shipment('123456789012');
        $actor = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $pickup = app(FirstDeliveryPickupService::class)->queue([$shipment->public_id], $actor);
        $order = $shipment->order;
        self::assertInstanceOf(Order::class, $order);
        $shipment->update(['local_status' => FirstDeliveryStatus::Cancelled, 'remote_state_code' => 6]);
        $order->update(['status' => 'annulee', 'archived_at' => now()]);

        app(PermanentlyDeleteArchivedOrdersAction::class)->handle([$order->public_reference], $actor);

        self::assertDatabaseMissing('first_delivery_shipments', ['id' => $shipment->id]);
        self::assertDatabaseHas('first_delivery_pickup_items', [
            'first_delivery_pickup_id' => $pickup->id,
            'first_delivery_shipment_id' => null,
            'barcode' => '123456789012',
            'order_reference' => $order->public_reference,
        ]);
    }

    private function configuration(): FirstDeliveryConfiguration
    {
        return FirstDeliveryConfiguration::query()->create([
            'mode' => 'manual',
            'api_base_url' => 'https://www.firstdeliverygroup.com/api/v2',
            'token_encrypted' => Crypt::encryptString('pickup-secret'),
        ]);
    }

    private function shipment(string $barcode): FirstDeliveryShipment
    {
        $locality = FirstDeliveryLocality::query()->firstOrCreate(['locality_id' => 101], [
            'locality_name' => 'Tunis', 'delegation_name' => 'Tunis Ville',
            'governorate_name' => 'Tunis', 'last_synced_at' => now(),
        ]);
        $order = Order::query()->create([
            'checkout_idempotency_key' => (string) str()->uuid(),
            'checkout_payload_hash' => str()->random(64),
            'status' => 'confirmee',
            'customer_name' => 'Client manifeste', 'customer_phone' => '22123456',
            'customer_city' => 'Tunis', 'customer_governorate' => 'Tunis',
            'first_delivery_locality_id' => $locality->locality_id,
            'customer_address' => 'Rue test', 'subtotal_millimes' => 50_000,
            'product_discount_millimes' => 0, 'promo_code_discount_millimes' => 0,
            'shipping_fee_millimes' => 0, 'total_millimes' => 50_000,
        ]);

        return FirstDeliveryShipment::query()->create([
            'order_id' => $order->id,
            'first_delivery_configuration_id' => FirstDeliveryConfiguration::query()->latest()->value('id'),
            'locality_id' => $locality->locality_id,
            'local_status' => FirstDeliveryStatus::Pending,
            'barcode' => $barcode,
            'remote_state_code' => 0,
            'remote_state' => 'En attente',
            'creation_mode' => 'manual',
            'sent_at' => now(),
        ]);
    }
}
