<?php

namespace Tests\Feature\MetaTracking;

use App\Domain\Commerce\Models\Order;
use App\Domain\MetaTracking\Models\MetaConfiguration;
use App\Domain\MetaTracking\Models\MetaEvent;
use App\Domain\MetaTracking\Services\MetaConversionsClient;
use App\Jobs\SendMetaEventJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class MetaConversionsClientTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_sends_only_to_the_fixed_meta_host_with_the_persisted_event_identity(): void
    {
        $event = $this->event();
        Http::fake(['graph.facebook.com/*' => Http::response(['events_received' => 1], 200)]);

        $result = app(MetaConversionsClient::class)->send($event->load('configuration'));

        self::assertTrue($result->accepted);
        Http::assertSent(function ($request) use ($event): bool {
            $payload = $request->data();

            return $request->url() === 'https://graph.facebook.com/v25.0/123456789/events'
                && $payload['data'][0]['event_name'] === 'ViewContent'
                && $payload['data'][0]['event_id'] === $event->event_id
                && $payload['data'][0]['event_time'] === $event->event_time->getTimestamp()
                && $payload['data'][0]['custom_data']['currency'] === 'TND';
        });
    }

    public function test_connection_test_uses_safe_synthetic_data_and_top_level_test_code(): void
    {
        config()->set('meta.test_event_source_url', 'http://localhost:8000');
        $configuration = $this->event()->configuration;
        $configuration->update(['test_mode' => true, 'test_event_code' => 'TEST123']);
        Http::fake(['graph.facebook.com/*' => Http::response(['events_received' => 1, 'fbtrace_id' => 'trace-safe'], 200)]);

        $result = app(MetaConversionsClient::class)->testConnection($configuration);

        self::assertTrue($result->accepted);
        self::assertTrue($result->requestSent);
        self::assertSame(1, $result->eventsReceived);
        Http::assertSent(function ($request): bool {
            $data = $request->data();

            return $request->url() === 'https://graph.facebook.com/v25.0/123456789/events'
                && $data['test_event_code'] === 'TEST123'
                && ! array_key_exists('test_event_code', $data['data'][0])
                && $data['data'][0]['event_source_url'] === 'http://localhost:8000/'
                && isset($data['data'][0]['user_data']['external_id']);
        });
    }

    public function test_meta_error_details_are_sanitized_and_returned_without_credentials(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => '<b>Invalid token</b>', 'code' => 190, 'error_subcode' => 463, 'fbtrace_id' => 'trace-id']], 401)]);

        $result = app(MetaConversionsClient::class)->send($this->event()->load('configuration'));

        self::assertSame('190', $result->metaErrorCode);
        self::assertSame('463', $result->metaErrorSubcode);
        self::assertSame('Invalid token', $result->metaMessage);
        self::assertSame('trace-id', $result->fbtraceId);
    }

    public function test_normal_server_event_uses_encrypted_browser_match_data(): void
    {
        $event = $this->event();
        $event->update(['user_data_encrypted' => Crypt::encryptString(json_encode([
            'client_ip_address' => '127.0.0.1',
            'client_user_agent' => 'Passion browser test',
            'fbp' => 'fb.1.1234567890.safe',
        ], JSON_THROW_ON_ERROR))]);
        Http::fake(['graph.facebook.com/*' => Http::response(['events_received' => 1], 200)]);

        app(MetaConversionsClient::class)->send($event->fresh('configuration'));

        Http::assertSent(function ($request): bool {
            $userData = $request->data()['data'][0]['user_data'];

            return $userData['client_ip_address'] === '127.0.0.1'
                && $userData['client_user_agent'] === 'Passion browser test'
                && $userData['fbp'] === 'fb.1.1234567890.safe';
        });
    }

    public function test_production_connection_test_never_sends_test_event_code(): void
    {
        $configuration = $this->event()->configuration;
        $configuration->update(['test_mode' => false, 'test_event_code' => 'MUST_NOT_BE_SENT']);
        Http::fake(['graph.facebook.com/*' => Http::response(['events_received' => 1], 200)]);

        app(MetaConversionsClient::class)->testConnection($configuration);

        Http::assertSent(fn ($request): bool => ! array_key_exists('test_event_code', $request->data()));
    }

    public function test_invalid_pixel_id_fails_before_an_outbound_request(): void
    {
        $configuration = $this->event()->configuration;
        $configuration->update(['pixel_id' => 'invalid']);
        Http::fake();

        $result = app(MetaConversionsClient::class)->testConnection($configuration);

        self::assertFalse($result->accepted);
        self::assertFalse($result->requestSent);
        self::assertSame('configuration_invalid', $result->classification);
        Http::assertNothingSent();
    }

    public function test_a_malformed_success_response_is_accepted_without_inventing_diagnostics(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response('not-json', 200)]);

        $result = app(MetaConversionsClient::class)->send($this->event()->load('configuration'));

        self::assertTrue($result->accepted);
        self::assertNull($result->eventsReceived);
        self::assertNull($result->fbtraceId);
    }

    public function test_connection_failure_is_temporary_and_reports_that_the_request_started(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::failedConnection()]);

        $result = app(MetaConversionsClient::class)->send($this->event()->load('configuration'));

        self::assertFalse($result->accepted);
        self::assertTrue($result->temporary);
        self::assertFalse($result->requestSent);
        self::assertContains($result->classification, ['timeout', 'network_error']);
    }

    public function test_rate_limits_are_temporary_and_expose_only_safe_retry_metadata(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'redacted']], 429, ['Retry-After' => '120'])]);

        $result = app(MetaConversionsClient::class)->send($this->event()->load('configuration'));

        self::assertFalse($result->accepted);
        self::assertTrue($result->temporary);
        self::assertSame('meta_rate_limited', $result->classification);
        self::assertSame(120, $result->retryAfterSeconds);
    }

    public function test_invalid_credentials_are_permanent(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'redacted']], 401)]);

        $result = app(MetaConversionsClient::class)->send($this->event()->load('configuration'));

        self::assertFalse($result->accepted);
        self::assertFalse($result->temporary);
        self::assertSame('meta_rejected', $result->classification);
    }

    public function test_all_outgoing_event_names_are_exact_meta_standard_names(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['events_received' => 1], 200)]);
        $names = ['PageView', 'ViewContent', 'Search', 'AddToCart', 'InitiateCheckout', 'Purchase'];
        foreach ($names as $name) {
            $event = $this->event($name);
            app(MetaConversionsClient::class)->send($event->fresh('configuration'));
        }

        $sentNames = collect(Http::recorded())->map(fn (array $pair): string => $pair[0]->data()['data'][0]['event_name'])->all();
        self::assertSame($names, $sentNames);
    }

    public function test_browser_non_observation_does_not_stop_server_delivery(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['events_received' => 1], 200)]);
        $event = $this->event();
        $event->update(['browser_state' => 'eligible']);
        (new SendMetaEventJob($event->public_id))->handle(app(MetaConversionsClient::class));

        self::assertSame('eligible', $event->fresh()->browser_state);
        self::assertSame('succeeded', $event->fresh()->capi_state);
    }

    public function test_configuration_failure_is_not_sent_and_remains_retryable(): void
    {
        Http::fake();
        $event = $this->event();
        $event->configuration->update(['capi_access_token_encrypted' => null]);
        (new SendMetaEventJob($event->public_id))->handle(app(MetaConversionsClient::class));

        self::assertSame('temporary_failure', $event->fresh()->capi_state);
        self::assertSame('configuration_invalid', $event->fresh()->last_error_classification);
        self::assertNull($event->fresh()->next_retry_at);
        $this->assertDatabaseHas('meta_event_attempts', ['meta_event_id' => $event->id, 'outcome' => 'configuration_error', 'request_sent' => false]);
        Http::assertNothingSent();
    }

    private function event(string $eventName = 'ViewContent'): MetaEvent
    {
        $configuration = MetaConfiguration::query()->create([
            'configuration_version' => random_int(1, 999999),
            'state' => 'active',
            'tracking_enabled' => true,
            'pixel_id' => '123456789',
            'capi_access_token_encrypted' => Crypt::encryptString('secret-that-must-never-be-exposed'),
            'activated_at' => now(),
        ]);

        $order = $eventName === 'Purchase' ? Order::query()->create([
            'checkout_idempotency_key' => (string) Str::uuid(),
            'checkout_payload_hash' => hash('sha256', Str::random()),
            'status' => 'nouvelle',
            'customer_name' => 'Client test',
            'customer_phone' => '20123456',
            'customer_city' => 'Tunis',
            'customer_address' => 'Adresse test',
            'subtotal_millimes' => 12_345,
            'product_discount_millimes' => 0,
            'promo_code_discount_millimes' => 0,
            'shipping_fee_millimes' => 0,
            'total_millimes' => 12_345,
        ]) : null;

        return MetaEvent::query()->create([
            'event_name' => $eventName,
            'order_id' => $order?->id,
            'meta_configuration_id' => $configuration->id,
            'event_time' => now(),
            'consent_policy_version' => 1,
            'marketing_consent' => true,
            'source_url' => 'https://passion.test/produits/serum',
            'context_summary' => ['product_public_id' => '01JPRODUCTPUBLICID0000000000', 'value_millimes' => 12345, 'currency' => 'TND'],
            'payload_hash' => hash('sha256', 'safe'),
        ]);
    }
}
