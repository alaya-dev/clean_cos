<?php

namespace Tests\Feature\Navex;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Commerce\Actions\TransitionOrderStatusAction;
use App\Domain\Commerce\Models\Order;
use App\Domain\Navex\Actions\SynchronizeNavexShipmentsAction;
use App\Domain\Navex\Enums\NavexDeliveryStatus;
use App\Domain\Navex\Models\NavexConfiguration;
use App\Domain\Navex\Models\NavexShipment;
use App\Domain\Navex\Services\NavexClient;
use App\Domain\Navex\Services\NavexShipmentAttemptRecorder;
use App\Domain\Navex\Services\NavexShipmentPayloadFactory;
use App\Domain\Navex\Services\NavexShipmentService;
use App\Domain\Navex\Services\NavexShipmentStateService;
use App\Jobs\CreateNavexShipmentJob;
use App\Jobs\DeleteNavexShipmentJob;
use App\Jobs\ReconcileNavexShipmentJob;
use App\Jobs\SynchronizeNavexShipmentJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class NavexIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_configuration_keeps_credentials_encrypted_and_never_returns_them(): void
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $response = $this->actingAs($superAdmin)->postJson('/api/v1/admin/navex/configuration', [
            'mode' => 'manual',
            'api_base_url' => 'https://app.navex.tn',
            'creation_credential' => 'creation-secret',
            'tracking_credential' => 'tracking-secret',
            'deletion_credential' => 'deletion-secret',
            'sender_name' => 'ToutDispo',
            'sender_location' => 'Tunis',
            'sender_governorate' => 'Tunis',
        ])->assertOk()->assertJsonPath('data.configuration.configuration_complete', true)
            ->assertJsonMissing(['creation-secret', 'tracking-secret', 'deletion-secret']);

        $configuration = NavexConfiguration::query()->firstOrFail();
        self::assertSame('creation-secret', Crypt::decryptString($configuration->creation_credential_encrypted));
        self::assertSame('Oui', $configuration->parcel_opening_option);
        self::assertSame('Oui', $response->json('data.configuration.parcel_opening_option'));
        self::assertTrue($response->json('data.configuration.creation_credential_configured'));
        $this->actingAs($superAdmin)->getJson('/api/v1/admin/navex/configuration')
            ->assertOk()
            ->assertJsonMissing(['creation-secret', 'tracking-secret', 'deletion-secret']);
    }

    public function test_blank_credential_update_preserves_the_existing_encrypted_value(): void
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $configuration = $this->configuration('manual');

        $this->actingAs($superAdmin)->postJson('/api/v1/admin/navex/configuration', [
            'mode' => 'manual',
            'api_base_url' => 'https://app.navex.tn',
            'creation_credential' => '',
            'tracking_credential' => '',
            'deletion_credential' => '',
            'sender_name' => 'ToutDispo',
            'sender_location' => 'Tunis',
            'sender_governorate' => 'Tunis',
            'parcel_opening_option' => 'Non',
        ])->assertOk()->assertJsonPath('data.configuration.configuration_complete', true);

        self::assertSame('creation-secret', Crypt::decryptString($configuration->fresh()->creation_credential_encrypted));
    }

    public function test_client_uses_the_documented_creation_path_and_exact_form_payload(): void
    {
        $configuration = $this->configuration('manual');
        Http::fake(['https://app.navex.tn/*' => Http::response(['status' => 1, 'code' => 'NX-100'], 201)]);

        $result = app(NavexClient::class)->create($configuration, [
            'prix' => '79.900', 'nom' => 'Client', 'gouvernerat' => 'Tunis', 'ville' => 'Tunis',
            'adresse' => 'Rue test', 'tel' => '22123456', 'tel2' => '', 'designation' => 'PC-TEST | 1 article',
            'nb_article' => 1, 'msg' => '', 'echange' => '', 'article' => '', 'nb_echange' => '',
            'ouvrir' => 'Non', 'sender_name' => 'ToutDispo', 'sender_location' => 'Tunis', 'sender_gouvernorat' => 'Tunis',
        ]);

        self::assertTrue($result->accepted);
        self::assertSame('NX-100', $result->trackingCode);
        Http::assertSent(function (Request $request): bool {
            self::assertSame('https://app.navex.tn/api/creation-secret/v1/post.php', $request->url());
            self::assertSame('79.900', $request['prix']);
            self::assertSame('Tunis', $request['gouvernerat']);
            self::assertSame('Non', $request['ouvrir']);

            return true;
        });
    }

    public function test_client_reads_a_twelve_digit_creation_barcode_from_navex_status_message(): void
    {
        $configuration = $this->configuration('manual');
        Http::fake(['https://app.navex.tn/*' => Http::response(['status' => 1, 'status_message' => '419978784191'], 201)]);

        $result = app(NavexClient::class)->create($configuration, ['nom' => 'Client']);

        self::assertTrue($result->accepted);
        self::assertSame('accepted', $result->classification);
        self::assertSame('419978784191', $result->trackingCode);
    }

    public function test_client_uses_documented_tracking_pending_and_deletion_form_fields(): void
    {
        $configuration = $this->configuration('manual');
        Http::fake(['https://app.navex.tn/*' => Http::response(['status' => 1, 'etat' => 'En attente'], 200)]);

        $client = app(NavexClient::class);
        $client->track($configuration, 'NX-100');
        $client->trackMany($configuration, ['NX-100', 'NX-101']);
        $client->pending($configuration);
        $client->delete($configuration, 'NX-100');

        Http::assertSent(function (Request $request): bool {
            if (str_contains($request->url(), '/tracking-secret/')) {
                return isset($request['code']) || isset($request['codes']) || isset($request['getattente']);
            }

            return str_contains($request->url(), '/deletion-secret/') && $request['delete_code'] === 'NX-100';
        });
        Http::assertSentCount(4);
    }

    public function test_manual_queue_uses_a_confirmed_order_and_never_uses_internal_ids_as_tracking_codes(): void
    {
        Queue::fake();
        $this->configuration('manual');
        $order = $this->order('confirmee');

        $shipment = app(NavexShipmentService::class)->queue($order, 'manual');

        self::assertSame(NavexDeliveryStatus::PendingSend, $shipment->status);
        self::assertNull($shipment->tracking_code);
        self::assertNotNull($shipment->request_snapshot_encrypted);
        self::assertDatabaseHas('navex_shipment_status_history', ['navex_shipment_id' => $shipment->id, 'status' => NavexDeliveryStatus::PendingSend->value]);
        Queue::assertPushed(CreateNavexShipmentJob::class, fn (CreateNavexShipmentJob $job): bool => $job->shipmentPublicId === $shipment->public_id);
    }

    public function test_payload_designation_uses_order_item_names_separated_by_a_slash(): void
    {
        $configuration = $this->configuration('manual');
        $order = $this->order('confirmee');
        $order->items()->create([
            'product_name_snapshot' => 'Shampooing',
            'regular_unit_price_millimes' => 10_000,
            'effective_unit_price_millimes' => 10_000,
            'variant_snapshot' => [['group' => 'Format', 'value' => '500 ml']],
            'quantity' => 2,
            'line_total_millimes' => 20_000,
        ]);
        $order->items()->create([
            'product_name_snapshot' => 'Masque',
            'regular_unit_price_millimes' => 10_000,
            'effective_unit_price_millimes' => 10_000,
            'quantity' => 1,
            'line_total_millimes' => 10_000,
        ]);

        $payload = app(NavexShipmentPayloadFactory::class)->make($order->load('items'), $configuration);

        self::assertSame('1 "Crème" // 2 "Shampooing" ("500 ml") // 1 "Masque"', $payload['designation']);
    }

    public function test_new_non_exchange_payload_uses_fixed_navex_exchange_and_package_rules(): void
    {
        $configuration = $this->configuration('manual');
        $order = $this->order('confirmee');

        $payload = app(NavexShipmentPayloadFactory::class)->make($order->load('items'), $configuration);

        self::assertSame('0', $payload['echange']);
        self::assertSame('', $payload['article']);
        self::assertSame('', $payload['nb_echange']);
        self::assertSame('Oui', $payload['ouvrir']);
        self::assertSame('fraagiiiiiiiiiiilleee', $payload['msg']);
    }

    public function test_navex_payload_sanitizes_all_provider_free_text_without_changing_local_unicode_data(): void
    {
        $configuration = $this->configuration('manual');
        $order = $this->order('confirmee');
        $order->update([
            'customer_name' => 'Client 👑 منى',
            'customer_governorate' => "Tu\u{200B}nis",
            'customer_city' => 'La 🧴 Marsa',
            'customer_address' => "\u{2066}Rue 12, Tunis\u{2069}",
            'is_exchange' => true,
            'exchange_article_designation' => 'Flacon ♻️ vide',
            'exchange_article_count' => 1,
        ]);
        $order->items()->firstOrFail()->update(['product_name_snapshot' => 'Crème ✨ réparatrice']);
        $configuration->update([
            'sender_name' => 'ToutDispo ✨',
            'sender_location' => 'Tunis 🧴',
            'sender_governorate' => "Tu\u{200B}nis",
        ]);

        $payload = app(NavexShipmentPayloadFactory::class)->make($order->load('items'), $configuration);

        self::assertSame('Client منى', $payload['nom']);
        self::assertSame('Tunis', $payload['gouvernerat']);
        self::assertSame('La Marsa', $payload['ville']);
        self::assertSame('Rue 12, Tunis', $payload['adresse']);
        self::assertSame('1 "Crème réparatrice"', $payload['designation']);
        self::assertSame('Flacon vide', $payload['article']);
        self::assertSame('ToutDispo', $payload['sender_name']);
        self::assertSame('Tunis', $payload['sender_location']);
        self::assertSame('Tunis', $payload['sender_gouvernorat']);
        self::assertSame('Client 👑 منى', $order->fresh()->customer_name);
        self::assertSame("Tu\u{200B}nis", $order->fresh()->customer_governorate);
        self::assertSame('La 🧴 Marsa', $order->fresh()->customer_city);
    }

    public function test_navex_payload_compatibility_normalizes_styled_unicode_without_changing_the_order(): void
    {
        $configuration = $this->configuration('manual');
        $order = $this->order('confirmee');
        $styledName = '𝑵𝒂ils 𝒃𝒚 𝒂𝒛𝒛𝒂';
        $order->update(['customer_name' => $styledName]);

        $payload = app(NavexShipmentPayloadFactory::class)->make($order->load('items'), $configuration);

        self::assertSame('Nails by azza', $payload['nom']);
        self::assertSame($styledName, $order->fresh()->customer_name);
    }

    public function test_navex_creation_job_sends_the_nfkc_normalized_customer_name_without_changing_the_order(): void
    {
        Queue::fake();
        $this->configuration('manual');
        $styledName = '𝑵𝒂ils 𝒃𝒚 𝒂𝒛𝒛𝒂';
        $order = $this->order('confirmee');
        $order->update(['customer_name' => $styledName]);
        $shipment = app(NavexShipmentService::class)->queue($order->fresh(), 'manual');
        Http::fake(['https://app.navex.tn/*' => Http::response(['status' => 1, 'code' => 'NX-NFKC'], 201)]);

        (new CreateNavexShipmentJob($shipment->public_id))->handle(
            app(NavexClient::class),
            app(NavexShipmentAttemptRecorder::class),
            app(NavexShipmentStateService::class),
        );

        Http::assertSent(fn (Request $request): bool => $request['nom'] === 'Nails by azza');
        self::assertSame($styledName, $order->fresh()->customer_name);
    }

    public function test_exchange_payload_serializes_the_designation_and_count_as_navex_strings(): void
    {
        $configuration = $this->configuration('manual');
        $order = $this->order('confirmee');
        $order->update(['is_exchange' => true, 'exchange_article_designation' => 'Ancien flacon', 'exchange_article_count' => 3]);

        $payload = app(NavexShipmentPayloadFactory::class)->make($order->load('items'), $configuration);

        self::assertSame('1', $payload['echange']);
        self::assertSame('Ancien flacon', $payload['article']);
        self::assertSame('3', $payload['nb_echange']);
        self::assertSame('Oui', $payload['ouvrir']);
        self::assertSame('fraagiiiiiiiiiiilleee', $payload['msg']);
    }

    public function test_legacy_checkout_exchange_values_are_used_when_building_a_new_navex_payload(): void
    {
        $configuration = $this->configuration('manual');
        $order = $this->order('confirmee');
        $order->checkoutValues()->createMany([
            ['field_key_snapshot' => 'echange', 'label_snapshot' => 'Échange', 'type_snapshot' => 'select', 'value' => 'Oui', 'is_required_snapshot' => false],
            ['field_key_snapshot' => 'article', 'label_snapshot' => 'Article à échanger', 'type_snapshot' => 'text', 'value' => 'Ancien flacon', 'is_required_snapshot' => false],
            ['field_key_snapshot' => 'nb_echange', 'label_snapshot' => 'Nombre à échanger', 'type_snapshot' => 'number', 'value' => 2, 'is_required_snapshot' => false],
        ]);

        $payload = app(NavexShipmentPayloadFactory::class)->make($order->load('items', 'checkoutValues'), $configuration);

        self::assertSame('1', $payload['echange']);
        self::assertSame('Ancien flacon', $payload['article']);
        self::assertSame('2', $payload['nb_echange']);
    }

    public function test_payload_designation_keeps_every_order_item_beyond_the_previous_limit(): void
    {
        $configuration = $this->configuration('manual');
        $order = $this->order('confirmee');
        $expectedParts = ['1 "Crème"'];

        foreach (range(1, 30) as $index) {
            $productName = 'Produit '.$index.' '.implode(' ', array_fill(0, 12, 'très long'));
            $order->items()->create([
                'product_name_snapshot' => $productName,
                'regular_unit_price_millimes' => 10_000,
                'effective_unit_price_millimes' => 10_000,
                'variant_snapshot' => [],
                'quantity' => 1,
                'line_total_millimes' => 10_000,
            ]);
            $expectedParts[] = '1 "'.$productName.'"';
        }

        $designation = app(NavexShipmentPayloadFactory::class)->make($order->load('items'), $configuration)['designation'];

        self::assertGreaterThan(500, mb_strlen($designation));
        self::assertSame(implode(' // ', $expectedParts), $designation);
        self::assertStringNotContainsString('...', $designation);
    }

    public function test_automatic_mode_creates_a_durable_shipment_only_after_order_confirmation_commits(): void
    {
        Queue::fake();
        $this->configuration('automatic');
        $order = $this->order('nouvelle');
        $actor = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        app(TransitionOrderStatusAction::class)->handle($order, 'confirmee', null, $actor->id);

        $shipment = NavexShipment::query()->where('order_id', $order->id)->firstOrFail();

        self::assertSame(NavexDeliveryStatus::PendingSend, $shipment->status);
        Queue::assertPushed(CreateNavexShipmentJob::class, fn (CreateNavexShipmentJob $job): bool => $job->shipmentPublicId === $shipment->public_id);
    }

    public function test_reconfirming_after_a_contact_attempt_never_creates_a_second_navex_shipment(): void
    {
        Queue::fake();
        $this->configuration('automatic');
        $order = $this->order('nouvelle');
        $actor = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $action = app(TransitionOrderStatusAction::class);

        $action->handle($order, 'confirmee', null, $actor->id);
        $action->handle($order->fresh(), 'tentative_1', null, $actor->id);
        $action->handle($order->fresh(), 'confirmee', null, $actor->id);

        self::assertDatabaseCount('navex_shipments', 1);
    }

    public function test_uncertain_creation_result_does_not_create_a_second_shipment(): void
    {
        $configuration = $this->configuration('manual');
        $order = $this->order('confirmee');
        $payload = app(NavexShipmentPayloadFactory::class)->make($order->load('items'), $configuration);
        $shipment = NavexShipment::query()->create([
            'order_id' => $order->id,
            'navex_configuration_id' => $configuration->id,
            'status' => NavexDeliveryStatus::UncertainResult,
            'creation_mode' => 'manual',
            'request_snapshot_encrypted' => Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR)),
        ]);

        self::assertDatabaseCount('navex_shipments', 1);
        self::assertSame(NavexDeliveryStatus::UncertainResult, $shipment->status);
    }

    public function test_creation_job_persists_the_tracking_code_and_a_sanitized_attempt(): void
    {
        Queue::fake();
        $this->configuration('manual');
        $shipment = app(NavexShipmentService::class)->queue($this->order('confirmee'), 'manual');
        Http::fake(['https://app.navex.tn/*' => Http::response(['status' => 1, 'code' => 'NX-100'], 201)]);

        (new CreateNavexShipmentJob($shipment->public_id))->handle(
            app(NavexClient::class),
            app(NavexShipmentAttemptRecorder::class),
            app(NavexShipmentStateService::class),
        );

        $shipment = $shipment->fresh();
        self::assertNotNull($shipment);
        self::assertSame(NavexDeliveryStatus::Accepted, $shipment->status);
        self::assertSame('En attente chez Navex', $shipment->status->label());
        self::assertSame('En attente chez Navex', $shipment->toArray()['status_label']);
        self::assertSame('NX-100', $shipment->tracking_code);
        Queue::assertPushed(SynchronizeNavexShipmentJob::class, fn (SynchronizeNavexShipmentJob $job): bool => $job->shipmentPublicId === $shipment->public_id);
        self::assertDatabaseHas('navex_shipment_attempts', [
            'navex_shipment_id' => $shipment->id,
            'operation' => 'creation',
            'request_sent' => true,
            'outcome' => 'accepted',
        ]);
        Http::assertSent(fn (Request $request): bool => $request['echange'] === '0'
            && $request['article'] === ''
            && $request['nb_echange'] === ''
            && $request['ouvrir'] === 'Oui'
            && $request['msg'] === 'fraagiiiiiiiiiiilleee');
    }

    public function test_exchange_creation_job_sends_exchange_fields_to_navex(): void
    {
        Queue::fake();
        $configuration = $this->configuration('manual');
        $order = $this->order('confirmee');
        $order->update([
            'is_exchange' => true,
            'exchange_article_designation' => 'Ancien flacon',
            'exchange_article_count' => 2,
        ]);
        $shipment = app(NavexShipmentService::class)->queue($order, 'manual');
        Http::fake(['https://app.navex.tn/*' => Http::response(['status' => 1, 'code' => 'NX-EXCHANGE'], 201)]);

        (new CreateNavexShipmentJob($shipment->public_id))->handle(
            app(NavexClient::class),
            app(NavexShipmentAttemptRecorder::class),
            app(NavexShipmentStateService::class),
        );

        Http::assertSent(fn (Request $request): bool => $request['echange'] === '1'
            && $request['article'] === 'Ancien flacon'
            && $request['nb_echange'] === '2'
            && $request['ouvrir'] === 'Oui'
            && $request['msg'] === 'fraagiiiiiiiiiiilleee');
    }

    public function test_retrying_an_unsent_shipment_refreshes_exchange_fields_from_the_order(): void
    {
        Queue::fake();
        $configuration = $this->configuration('manual');
        $order = $this->order('confirmee');
        $oldPayload = app(NavexShipmentPayloadFactory::class)->make($order->load('items'), $configuration);
        $order->update([
            'is_exchange' => true,
            'exchange_article_designation' => 'Ancien flacon',
            'exchange_article_count' => 2,
        ]);
        $shipment = NavexShipment::query()->create([
            'order_id' => $order->id,
            'navex_configuration_id' => $configuration->id,
            'status' => NavexDeliveryStatus::SynchronizationError,
            'creation_mode' => 'manual',
            'request_snapshot_encrypted' => Crypt::encryptString(json_encode($oldPayload, JSON_THROW_ON_ERROR)),
        ]);

        $queued = app(NavexShipmentService::class)->retry($order->fresh());
        $snapshot = json_decode(Crypt::decryptString((string) $queued->fresh()->request_snapshot_encrypted), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame($shipment->id, $queued->id);
        self::assertSame('1', $snapshot['echange']);
        self::assertSame('Ancien flacon', $snapshot['article']);
        self::assertSame('2', $snapshot['nb_echange']);
        Queue::assertPushed(CreateNavexShipmentJob::class, fn (CreateNavexShipmentJob $job): bool => $job->shipmentPublicId === $shipment->public_id);
    }

    public function test_successful_creation_without_a_barcode_is_pending_at_navex_not_uncertain(): void
    {
        Queue::fake();
        $this->configuration('manual');
        $shipment = app(NavexShipmentService::class)->queue($this->order('confirmee'), 'manual');
        Http::fake(['https://app.navex.tn/*' => Http::response(['status' => 1, 'status_message' => 'Product Added.'], 201)]);

        (new CreateNavexShipmentJob($shipment->public_id))->handle(
            app(NavexClient::class),
            app(NavexShipmentAttemptRecorder::class),
            app(NavexShipmentStateService::class),
        );

        $shipment = $shipment->fresh();
        self::assertNotNull($shipment);
        self::assertSame(NavexDeliveryStatus::Accepted, $shipment->status);
        self::assertNull($shipment->tracking_code);
        self::assertSame('En attente chez Navex', $shipment->status->label());
        Queue::assertPushed(ReconcileNavexShipmentJob::class, fn (ReconcileNavexShipmentJob $job): bool => $job->shipmentPublicId === $shipment->public_id);
    }

    public function test_successful_creation_with_a_status_message_barcode_skips_pending_reconciliation(): void
    {
        Queue::fake();
        $this->configuration('manual');
        $shipment = app(NavexShipmentService::class)->queue($this->order('confirmee'), 'manual');
        Http::fake(['https://app.navex.tn/*' => Http::response(['status' => 1, 'status_message' => '419978784191'], 201)]);

        (new CreateNavexShipmentJob($shipment->public_id))->handle(
            app(NavexClient::class),
            app(NavexShipmentAttemptRecorder::class),
            app(NavexShipmentStateService::class),
        );

        $shipment = $shipment->fresh();
        self::assertNotNull($shipment);
        self::assertSame(NavexDeliveryStatus::Accepted, $shipment->status);
        self::assertSame('419978784191', $shipment->tracking_code);
        Queue::assertPushed(SynchronizeNavexShipmentJob::class, fn (SynchronizeNavexShipmentJob $job): bool => $job->shipmentPublicId === $shipment->public_id);
        Queue::assertNotPushed(ReconcileNavexShipmentJob::class);
    }

    public function test_an_accepted_shipment_without_a_tracking_code_can_be_synchronized_immediately(): void
    {
        Queue::fake();
        $configuration = $this->configuration('manual');
        $order = $this->order('confirmee');
        $shipment = NavexShipment::query()->create([
            'order_id' => $order->id,
            'navex_configuration_id' => $configuration->id,
            'status' => NavexDeliveryStatus::Accepted,
            'creation_mode' => 'manual',
        ]);
        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/admin/orders/'.$order->public_reference.'/navex/synchronize')
            ->assertOk()
            ->assertJsonPath('data.notice', 'Recherche du code de suivi Navex en cours.');

        Queue::assertPushed(ReconcileNavexShipmentJob::class, fn (ReconcileNavexShipmentJob $job): bool => $job->shipmentPublicId === $shipment->public_id);
    }

    public function test_navex_attempt_counters_continue_after_the_legacy_tinyint_limit(): void
    {
        $configuration = $this->configuration('manual');
        $shipment = NavexShipment::query()->create([
            'order_id' => $this->order('confirmee')->id,
            'navex_configuration_id' => $configuration->id,
            'status' => NavexDeliveryStatus::Accepted,
            'tracking_code' => 'NX-255',
            'creation_mode' => 'manual',
            'attempt_count' => 255,
        ]);
        $shipment->attempts()->create([
            'operation' => 'batch_tracking',
            'attempt_number' => 255,
            'outcome' => 'accepted',
            'attempted_at' => now(),
        ]);
        Http::fake(['https://app.navex.tn/*' => Http::response([
            'status' => 1,
            'results' => [['status' => 1, 'code' => 'NX-255', 'etat' => 'En attente']],
        ], 200)]);

        self::assertSame(1, app(SynchronizeNavexShipmentsAction::class)->handle(50));
        self::assertDatabaseHas('navex_shipments', ['id' => $shipment->id, 'attempt_count' => 256]);
        self::assertDatabaseHas('navex_shipment_attempts', [
            'navex_shipment_id' => $shipment->id,
            'operation' => 'batch_tracking',
            'attempt_number' => 256,
        ]);
    }

    public function test_failed_batch_tracking_preserves_every_last_known_provider_state(): void
    {
        $configuration = $this->configuration('manual');
        $shipments = collect([
            [NavexDeliveryStatus::Pending, 'En attente', 'NX-BATCH-PENDING'],
            [NavexDeliveryStatus::InDelivery, 'En cours', 'NX-BATCH-DELIVERY'],
            [NavexDeliveryStatus::ManualActionRequired, 'Au magasin', 'NX-BATCH-STORE'],
        ])->map(function (array $values) use ($configuration): NavexShipment {
            $shipment = NavexShipment::query()->create([
                'order_id' => $this->order('confirmee')->id,
                'navex_configuration_id' => $configuration->id,
                'status' => $values[0],
                'tracking_code' => $values[2],
                'raw_status' => $values[1],
                'last_synchronized_at' => now()->subMinute(),
                'sent_at' => now()->subDay(),
                'creation_mode' => 'manual',
            ]);

            return $shipment;
        });
        Http::fake(['https://app.navex.tn/*' => Http::failedConnection()]);

        self::assertSame(3, app(SynchronizeNavexShipmentsAction::class)->handle(50));

        foreach ($shipments as $shipment) {
            $fresh = $shipment->fresh();
            self::assertNotNull($fresh);
            self::assertSame($shipment->status, $fresh->status);
            self::assertSame($shipment->raw_status, $fresh->raw_status);
            self::assertNotSame(NavexDeliveryStatus::SynchronizationError, $fresh->status);
            self::assertDatabaseHas('navex_shipment_attempts', [
                'navex_shipment_id' => $shipment->id,
                'operation' => 'batch_tracking',
                'error_classification' => 'network_uncertain',
            ]);
        }
    }

    public function test_successful_batch_tracking_updates_state_after_a_previous_refresh_failure(): void
    {
        $configuration = $this->configuration('manual');
        $shipment = NavexShipment::query()->create([
            'order_id' => $this->order('confirmee')->id,
            'navex_configuration_id' => $configuration->id,
            'status' => NavexDeliveryStatus::InDelivery,
            'tracking_code' => 'NX-BATCH-RECOVER',
            'raw_status' => 'Au magasin',
            'last_synchronized_at' => now()->subMinute(),
            'sent_at' => now()->subDay(),
            'creation_mode' => 'manual',
        ]);
        Http::fake(['https://app.navex.tn/*' => Http::sequence()
            ->push([], 503)
            ->push(['status' => 1, 'results' => [['status' => 1, 'code' => 'NX-BATCH-RECOVER', 'etat' => 'En attente']]], 200)]);

        app(SynchronizeNavexShipmentsAction::class)->handle(50);
        self::assertSame(NavexDeliveryStatus::InDelivery, $shipment->fresh()->status);

        app(SynchronizeNavexShipmentsAction::class)->handle(50);
        $shipment = $shipment->fresh();
        self::assertNotNull($shipment);
        self::assertSame(NavexDeliveryStatus::Pending, $shipment->status);
        self::assertSame('En attente', $shipment->raw_status);
        self::assertNull($shipment->last_error_classification);
        self::assertNull($shipment->next_retry_at);
    }

    public function test_automatic_batch_tracking_excludes_shipments_at_or_older_than_90_days(): void
    {
        $configuration = $this->configuration('manual');
        $eligible = NavexShipment::query()->create([
            'order_id' => $this->order('confirmee')->id,
            'navex_configuration_id' => $configuration->id,
            'status' => NavexDeliveryStatus::Pending,
            'tracking_code' => 'NX-90-ELIGIBLE',
            'sent_at' => now()->subDays(10),
            'creation_mode' => 'manual',
        ]);
        $legacyNullSentAt = NavexShipment::query()->create([
            'order_id' => $this->order('confirmee')->id,
            'navex_configuration_id' => $configuration->id,
            'status' => NavexDeliveryStatus::Pending,
            'tracking_code' => 'NX-90-NULL',
            'creation_mode' => 'manual',
        ]);
        $exactBoundary = NavexShipment::query()->create([
            'order_id' => $this->order('confirmee')->id,
            'navex_configuration_id' => $configuration->id,
            'status' => NavexDeliveryStatus::InDelivery,
            'tracking_code' => 'NX-90-EXACT',
            'raw_status' => 'En cours',
            'sent_at' => now()->subDays(90),
            'creation_mode' => 'manual',
        ]);
        $old = NavexShipment::query()->create([
            'order_id' => $this->order('confirmee')->id,
            'navex_configuration_id' => $configuration->id,
            'status' => NavexDeliveryStatus::ManualActionRequired,
            'tracking_code' => 'NX-90-OLD',
            'raw_status' => 'Au magasin',
            'sent_at' => now()->subDays(91),
            'creation_mode' => 'manual',
        ]);
        $terminal = NavexShipment::query()->create([
            'order_id' => $this->order('confirmee')->id,
            'navex_configuration_id' => $configuration->id,
            'status' => NavexDeliveryStatus::DeliveredPaid,
            'tracking_code' => 'NX-90-TERMINAL',
            'sent_at' => now()->subDay(),
            'creation_mode' => 'manual',
        ]);
        $retryLater = NavexShipment::query()->create([
            'order_id' => $this->order('confirmee')->id,
            'navex_configuration_id' => $configuration->id,
            'status' => NavexDeliveryStatus::Pending,
            'tracking_code' => 'NX-90-RETRY',
            'sent_at' => now()->subDay(),
            'next_retry_at' => now()->addHour(),
            'creation_mode' => 'manual',
        ]);
        Http::fake(['https://app.navex.tn/*' => Http::response(['status' => 1, 'results' => [
            ['status' => 1, 'code' => 'NX-90-ELIGIBLE', 'etat' => 'En attente'],
            ['status' => 1, 'code' => 'NX-90-NULL', 'etat' => 'En attente'],
        ]], 200)]);

        self::assertSame(2, app(SynchronizeNavexShipmentsAction::class)->handle(50));
        self::assertSame(1, $eligible->fresh()->attempts()->count());
        self::assertSame(1, $legacyNullSentAt->fresh()->attempts()->count());
        self::assertSame(0, $exactBoundary->fresh()->attempts()->count());
        self::assertSame('Au magasin', $old->fresh()->raw_status);
        self::assertSame(0, $old->fresh()->attempts()->count());
        self::assertSame(0, $terminal->fresh()->attempts()->count());
        self::assertSame(0, $retryLater->fresh()->attempts()->count());
    }

    public function test_include_old_explicitly_allows_manual_recovery_without_changing_scheduler_selection(): void
    {
        $configuration = $this->configuration('manual');
        $shipment = NavexShipment::query()->create([
            'order_id' => $this->order('confirmee')->id,
            'navex_configuration_id' => $configuration->id,
            'status' => NavexDeliveryStatus::ManualActionRequired,
            'tracking_code' => 'NX-90-MANUAL',
            'raw_status' => 'Au magasin',
            'sent_at' => now()->subDays(120),
            'creation_mode' => 'manual',
        ]);
        Http::fake(['https://app.navex.tn/*' => Http::response(['status' => 1, 'results' => [['status' => 1, 'code' => 'NX-90-MANUAL', 'etat' => 'En attente']]], 200)]);

        self::assertSame(1, app(SynchronizeNavexShipmentsAction::class)->handle(50, true));
        self::assertSame(NavexDeliveryStatus::Pending, $shipment->fresh()->status);
    }

    public function test_scheduler_recovers_a_historical_creation_barcode_without_resending_the_parcel(): void
    {
        Queue::fake();
        $configuration = $this->configuration('manual');
        $shipment = NavexShipment::query()->create([
            'order_id' => $this->order('confirmee')->id,
            'navex_configuration_id' => $configuration->id,
            'status' => NavexDeliveryStatus::ManualActionRequired,
            'creation_mode' => 'automatic',
            'sent_at' => now()->subMinute(),
            'last_error_classification' => 'reconciliation_ambiguous',
        ]);
        $shipment->attempts()->create([
            'operation' => 'creation',
            'attempt_number' => 1,
            'request_sent' => true,
            'outcome' => 'accepted_without_tracking_code',
            'safe_message' => '419978784191',
            'attempted_at' => now()->subMinute(),
        ]);

        self::assertSame(1, app(SynchronizeNavexShipmentsAction::class)->handle(50));

        $shipment = $shipment->fresh();
        self::assertNotNull($shipment);
        self::assertSame(NavexDeliveryStatus::Accepted, $shipment->status);
        self::assertSame('419978784191', $shipment->tracking_code);
        self::assertNull($shipment->last_error_classification);
        Queue::assertPushed(SynchronizeNavexShipmentJob::class, fn (SynchronizeNavexShipmentJob $job): bool => $job->shipmentPublicId === $shipment->public_id);
        Queue::assertNotPushed(CreateNavexShipmentJob::class);
    }

    public function test_scheduler_recovers_five_historical_manual_action_shipments_without_calling_navex(): void
    {
        Queue::fake();
        Http::fake();
        $configuration = $this->configuration('manual');
        $shipments = collect(range(1, 5))->map(function (int $number) use ($configuration): NavexShipment {
            $shipment = NavexShipment::query()->create([
                'order_id' => $this->order('confirmee')->id,
                'navex_configuration_id' => $configuration->id,
                'status' => NavexDeliveryStatus::ManualActionRequired,
                'creation_mode' => 'automatic',
                'sent_at' => now()->subMinute(),
                'last_error_classification' => 'reconciliation_ambiguous',
            ]);
            $shipment->attempts()->create([
                'operation' => 'creation',
                'attempt_number' => 1,
                'request_sent' => true,
                'outcome' => 'accepted_without_tracking_code',
                'safe_message' => '41997878419'.$number,
                'attempted_at' => now()->subMinute(),
            ]);

            return $shipment;
        });

        self::assertSame(5, app(SynchronizeNavexShipmentsAction::class)->handle(5));

        foreach ($shipments as $shipment) {
            $fresh = $shipment->fresh();
            self::assertNotNull($fresh);
            self::assertSame(NavexDeliveryStatus::Accepted, $fresh->status);
            self::assertTrue(ctype_digit((string) $fresh->tracking_code));
            self::assertSame(12, strlen((string) $fresh->tracking_code));
            self::assertNull($fresh->last_error_classification);
        }
        self::assertCount(5, Queue::pushed(SynchronizeNavexShipmentJob::class));
        Queue::assertNotPushed(CreateNavexShipmentJob::class);
        Http::assertNothingSent();
    }

    public function test_temporary_creation_failure_retries_after_a_long_shipment_history(): void
    {
        Queue::fake();
        $this->configuration('manual');
        $shipment = app(NavexShipmentService::class)->queue($this->order('confirmee'), 'manual');
        $shipment->update(['attempt_count' => 255]);
        Http::fake(['https://app.navex.tn/*' => Http::response([], 503)]);
        $job = (new CreateNavexShipmentJob($shipment->public_id))->withFakeQueueInteractions();

        $job->handle(
            app(NavexClient::class),
            app(NavexShipmentAttemptRecorder::class),
            app(NavexShipmentStateService::class),
        );

        $job->assertReleased(60);
        self::assertDatabaseHas('navex_shipments', [
            'id' => $shipment->id,
            'attempt_count' => 256,
            'status' => NavexDeliveryStatus::PendingSend->value,
        ]);
        self::assertSame(1, $shipment->fresh()->attempts()->where('operation', 'creation')->max('attempt_number'));
    }

    public function test_verified_provider_delivery_changes_the_order_once_and_keeps_raw_status_history(): void
    {
        $configuration = $this->configuration('manual');
        $order = $this->order('confirmee');
        $shipment = NavexShipment::query()->create([
            'order_id' => $order->id,
            'navex_configuration_id' => $configuration->id,
            'status' => NavexDeliveryStatus::Accepted,
            'tracking_code' => 'NX-100',
            'creation_mode' => 'manual',
        ]);
        $provider = ['status' => 1, 'code' => 'NX-100', 'etat' => 'Livrer Paye', 'motif' => null];

        app(NavexShipmentStateService::class)->synchronize($shipment, $provider);
        app(NavexShipmentStateService::class)->synchronize($shipment, $provider);

        self::assertSame('confirmee', $order->fresh()->status);
        self::assertSame(NavexDeliveryStatus::DeliveredPaid, $shipment->fresh()->status);
        self::assertDatabaseCount('navex_shipment_status_history', 1);
        self::assertDatabaseCount('order_status_history', 0);
    }

    public function test_successful_unknown_tracking_status_recovers_from_a_synchronization_error(): void
    {
        $configuration = $this->configuration('manual');
        $shipment = NavexShipment::query()->create([
            'order_id' => $this->order('confirmee')->id,
            'navex_configuration_id' => $configuration->id,
            'status' => NavexDeliveryStatus::SynchronizationError,
            'tracking_code' => 'NX-UNKNOWN',
            'raw_status' => 'En attente',
            'last_error_classification' => 'timeout',
            'next_retry_at' => now()->addMinute(),
            'creation_mode' => 'manual',
        ]);

        app(NavexShipmentStateService::class)->synchronize($shipment, [
            'status' => 1,
            'code' => 'NX-UNKNOWN',
            'etat' => 'Au magasin',
            'motif' => null,
            'pre_etat' => 'En attente',
        ]);

        $shipment = $shipment->fresh();
        self::assertNotNull($shipment);
        self::assertSame(NavexDeliveryStatus::Accepted, $shipment->status);
        self::assertSame('Au magasin', $shipment->raw_status);
        self::assertSame('En attente', $shipment->previous_raw_status);
        self::assertNull($shipment->last_error_classification);
        self::assertNull($shipment->next_retry_at);
        self::assertNotNull($shipment->last_synchronized_at);
        self::assertSame('Navex : Au magasin', $shipment->display_status_label);
        self::assertDatabaseHas('navex_shipment_status_history', [
            'navex_shipment_id' => $shipment->id,
            'status' => NavexDeliveryStatus::Accepted->value,
            'raw_status' => 'Au magasin',
        ]);

        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->actingAs($admin)->getJson('/api/v1/admin/orders/'.$shipment->order->public_reference)
            ->assertOk()
            ->assertJsonPath('data.navex.shipment.display_status_label', 'Navex : Au magasin');
        $this->actingAs($admin)->getJson('/api/v1/admin/orders')
            ->assertOk()
            ->assertJsonPath('data.data.0.navex_delivery.label', 'Navex : Au magasin');
    }

    public function test_known_and_future_provider_statuses_are_normalized_without_preserving_stale_state(): void
    {
        $configuration = $this->configuration('manual');
        $shipment = NavexShipment::query()->create([
            'order_id' => $this->order('confirmee')->id,
            'navex_configuration_id' => $configuration->id,
            'status' => NavexDeliveryStatus::SynchronizationError,
            'tracking_code' => 'NX-MAPPING',
            'creation_mode' => 'manual',
        ]);

        foreach ([
            ' Livrer Paye ' => NavexDeliveryStatus::DeliveredPaid,
            'Retourné' => NavexDeliveryStatus::Returned,
            'reTourne' => NavexDeliveryStatus::Returned,
            'Supprime' => NavexDeliveryStatus::Cancelled,
            ' SUPPRIMÉ ' => NavexDeliveryStatus::Cancelled,
            'En attente' => NavexDeliveryStatus::Pending,
            ' en COURS ' => NavexDeliveryStatus::InDelivery,
            'Rtn depot' => NavexDeliveryStatus::Accepted,
            'Nouveau statut Navex 2027' => NavexDeliveryStatus::Accepted,
        ] as $rawStatus => $expectedStatus) {
            $shipment->update(['status' => NavexDeliveryStatus::SynchronizationError, 'last_error_classification' => 'timeout']);
            app(NavexShipmentStateService::class)->synchronize($shipment, ['status' => 1, 'etat' => $rawStatus]);
            $shipment = $shipment->fresh();

            self::assertNotNull($shipment);
            self::assertSame($expectedStatus, $shipment->status);
            self::assertSame(trim($rawStatus), $shipment->raw_status);
            self::assertNull($shipment->last_error_classification);
            self::assertNull($shipment->next_retry_at);
        }
    }

    public function test_successful_unknown_tracking_status_preserves_a_meaningful_last_known_state(): void
    {
        $configuration = $this->configuration('manual');
        $shipment = NavexShipment::query()->create([
            'order_id' => $this->order('confirmee')->id,
            'navex_configuration_id' => $configuration->id,
            'status' => NavexDeliveryStatus::InDelivery,
            'tracking_code' => 'NX-UNKNOWN-PRESERVE',
            'raw_status' => 'En cours',
            'last_synchronized_at' => now()->subMinute(),
            'creation_mode' => 'manual',
        ]);

        app(NavexShipmentStateService::class)->synchronize($shipment, [
            'status' => 1,
            'code' => 'NX-UNKNOWN-PRESERVE',
            'etat' => 'Au magasin',
        ]);

        $shipment = $shipment->fresh();
        self::assertNotNull($shipment);
        self::assertSame(NavexDeliveryStatus::InDelivery, $shipment->status);
        self::assertSame('Au magasin', $shipment->raw_status);
        self::assertSame('Navex : Au magasin', $shipment->display_status_label);
        self::assertNull($shipment->last_error_classification);
        self::assertNull($shipment->next_retry_at);
        self::assertDatabaseHas('navex_shipment_status_history', [
            'navex_shipment_id' => $shipment->id,
            'status' => NavexDeliveryStatus::InDelivery->value,
            'raw_status' => 'Au magasin',
        ]);
    }

    public function test_failed_tracking_refresh_preserves_the_last_known_provider_status_and_retries(): void
    {
        $configuration = $this->configuration('manual');
        $shipment = NavexShipment::query()->create([
            'order_id' => $this->order('confirmee')->id,
            'navex_configuration_id' => $configuration->id,
            'status' => NavexDeliveryStatus::Pending,
            'tracking_code' => 'NX-FAILURE',
            'creation_mode' => 'manual',
        ]);
        Http::fake(['https://app.navex.tn/*' => Http::response([], 503)]);
        $job = (new SynchronizeNavexShipmentJob($shipment->public_id))->withFakeQueueInteractions();

        $job->handle(app(NavexClient::class), app(NavexShipmentAttemptRecorder::class), app(NavexShipmentStateService::class));

        $shipment = $shipment->fresh();
        self::assertNotNull($shipment);
        self::assertSame(NavexDeliveryStatus::Pending, $shipment->status);
        self::assertNotNull($shipment->next_retry_at);
        self::assertSame('temporary_failure', $shipment->last_error_classification);
        $job->assertReleased(60);
    }

    public function test_provider_deleted_status_marks_the_shipment_cancelled_without_changing_the_order_status(): void
    {
        $configuration = $this->configuration('manual');
        $order = $this->order('confirmee');
        $shipment = NavexShipment::query()->create([
            'order_id' => $order->id,
            'navex_configuration_id' => $configuration->id,
            'status' => NavexDeliveryStatus::Pending,
            'tracking_code' => 'NX-101',
            'creation_mode' => 'manual',
        ]);

        app(NavexShipmentStateService::class)->synchronize($shipment, [
            'status' => 1,
            'code' => 'NX-101',
            'etat' => 'Supprime',
            'motif' => null,
        ]);

        self::assertSame(NavexDeliveryStatus::Cancelled, $shipment->fresh()->status);
        self::assertSame('Supprime', $shipment->fresh()->raw_status);
        self::assertSame('confirmee', $order->fresh()->status);
    }

    public function test_navex_cancellation_is_available_only_while_the_provider_reports_pending(): void
    {
        Queue::fake();
        $configuration = $this->configuration('manual');
        $order = $this->order('confirmee');
        $shipment = NavexShipment::query()->create([
            'order_id' => $order->id,
            'navex_configuration_id' => $configuration->id,
            'status' => NavexDeliveryStatus::Pending,
            'tracking_code' => 'NX-102',
            'raw_status' => 'Au magasin',
            'last_synchronized_at' => now(),
            'creation_mode' => 'manual',
        ]);
        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $this->actingAs($admin)->postJson('/api/v1/admin/orders/'.$order->public_reference.'/navex/cancel', [
            'confirm_cancellation' => true,
        ])->assertUnprocessable();

        $shipment->update(['raw_status' => 'En attente']);

        $this->actingAs($admin)->postJson('/api/v1/admin/orders/'.$order->public_reference.'/navex/cancel', [
            'confirm_cancellation' => true,
        ])->assertOk();

        Queue::assertPushed(DeleteNavexShipmentJob::class, fn (DeleteNavexShipmentJob $job): bool => $job->shipmentPublicId === $shipment->public_id);
    }

    public function test_reconciliation_without_a_match_allows_a_guarded_retry_of_the_same_shipment(): void
    {
        Queue::fake();
        $configuration = $this->configuration('manual');
        $order = $this->order('confirmee');
        $shipment = NavexShipment::query()->create([
            'order_id' => $order->id,
            'navex_configuration_id' => $configuration->id,
            'status' => NavexDeliveryStatus::UncertainResult,
            'creation_mode' => 'manual',
        ]);
        Http::fake(['https://app.navex.tn/*' => Http::response(['status' => 1, 'colis' => []], 200)]);

        (new ReconcileNavexShipmentJob($shipment->public_id))->handle(
            app(NavexClient::class),
            app(NavexShipmentAttemptRecorder::class),
            app(NavexShipmentStateService::class),
            app(NavexShipmentPayloadFactory::class),
        );
        $retried = app(NavexShipmentService::class)->retry($order);

        self::assertSame($shipment->id, $retried->id);
        self::assertSame(NavexDeliveryStatus::PendingSend, $retried->status);
        self::assertDatabaseCount('navex_shipments', 1);
        Queue::assertPushed(CreateNavexShipmentJob::class, fn (CreateNavexShipmentJob $job): bool => $job->shipmentPublicId === $retried->public_id);
    }

    public function test_reconciliation_requires_manual_action_when_multiple_pending_parcels_are_indistinguishable(): void
    {
        $configuration = $this->configuration('manual');
        $order = $this->order('confirmee');
        $payload = app(NavexShipmentPayloadFactory::class)->make($order, $configuration);
        $shipment = NavexShipment::query()->create([
            'order_id' => $order->id,
            'navex_configuration_id' => $configuration->id,
            'status' => NavexDeliveryStatus::UncertainResult,
            'creation_mode' => 'manual',
            'request_snapshot_encrypted' => Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR)),
        ]);
        Http::fake(['https://app.navex.tn/*' => Http::response([
            'status' => 1,
            'colis' => [
                ['code_barre' => 'NX-AMBIGUOUS-1', 'designation' => $payload['designation'], 'prix' => $payload['prix']],
                ['code_barre' => 'NX-AMBIGUOUS-2', 'designation' => $payload['designation'], 'prix' => $payload['prix']],
            ],
        ], 200)]);

        (new ReconcileNavexShipmentJob($shipment->public_id))->handle(
            app(NavexClient::class),
            app(NavexShipmentAttemptRecorder::class),
            app(NavexShipmentStateService::class),
            app(NavexShipmentPayloadFactory::class),
        );

        $shipment = $shipment->fresh();
        self::assertNotNull($shipment);
        self::assertSame(NavexDeliveryStatus::ManualActionRequired, $shipment->status);
        self::assertSame('reconciliation_ambiguous', $shipment->last_error_classification);
        self::assertNull($shipment->tracking_code);
        self::assertDatabaseHas('navex_shipment_attempts', [
            'navex_shipment_id' => $shipment->id,
            'operation' => 'reconciliation',
            'outcome' => 'accepted',
        ]);
    }

    private function configuration(string $mode): NavexConfiguration
    {
        return NavexConfiguration::query()->create([
            'mode' => $mode,
            'api_base_url' => 'https://app.navex.tn',
            'creation_credential_encrypted' => Crypt::encryptString('creation-secret'),
            'tracking_credential_encrypted' => Crypt::encryptString('tracking-secret'),
            'deletion_credential_encrypted' => Crypt::encryptString('deletion-secret'),
            'sender_name' => 'ToutDispo',
            'sender_location' => 'Tunis',
            'sender_governorate' => 'Tunis',
            'parcel_opening_option' => 'Non',
        ]);
    }

    private function order(string $status): Order
    {
        $category = Category::query()->create(['name' => 'Soin', 'slug' => 'soin-'.str()->random(8), 'is_active' => true]);
        $product = Product::query()->create(['category_id' => $category->id, 'name' => 'Crème', 'slug' => 'creme-'.str()->random(8), 'regular_price_millimes' => 79_900, 'stock_quantity' => 10, 'is_active' => true]);
        $order = Order::query()->create([
            'checkout_idempotency_key' => (string) str()->uuid(),
            'checkout_payload_hash' => str()->random(64),
            'status' => $status,
            'customer_name' => 'Client test',
            'customer_phone' => '22123456',
            'customer_city' => 'Tunis',
            'customer_governorate' => 'Tunis',
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
