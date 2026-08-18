<?php

namespace Tests\Feature\MetaTracking;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\MetaTracking\Models\MetaConfiguration;
use App\Domain\MetaTracking\Models\MetaEvent;
use App\Domain\MetaTracking\Services\MarketingConsentService;
use App\Domain\MetaTracking\Services\MetaConversionsClient;
use App\Jobs\DispatchMetaEventJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MetaReliabilityContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_independent_consented_visitors_create_separate_durable_outbox_events(): void
    {
        Queue::fake();
        $this->activeConfiguration();
        $this->grantMarketingConsent();
        $category = Category::query()->create(['name' => 'Visage', 'slug' => 'visage', 'is_active' => true]);
        $product = Product::query()->create([
            'category_id' => $category->id,
            'name' => 'Sérum',
            'slug' => 'serum',
            'regular_price_millimes' => 30_000,
            'stock_quantity' => 10,
            'is_active' => true,
            'published_at' => now(),
        ]);

        $first = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->withHeader('User-Agent', 'Passion test visitor A')
            ->postJson('/api/v1/public/meta/events', [
                'event_name' => 'ViewContent',
                'source_url' => 'http://localhost/produits/serum',
                'product_public_id' => $product->public_id,
            ])
            ->assertOk();
        $second = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.11'])
            ->withHeader('User-Agent', 'Passion test visitor B')
            ->postJson('/api/v1/public/meta/events', [
                'event_name' => 'ViewContent',
                'source_url' => 'http://localhost/produits/serum',
                'product_public_id' => $product->public_id,
            ])
            ->assertOk();

        self::assertNotSame($first->json('data.event.event_id'), $second->json('data.event.event_id'));
        self::assertDatabaseCount('meta_events', 2);
        Queue::assertPushed(DispatchMetaEventJob::class, 2);

        $storedAgents = MetaEvent::query()
            ->orderBy('id')
            ->get()
            ->map(fn (MetaEvent $event): string => (string) data_get(json_decode(Crypt::decryptString((string) $event->user_data_encrypted), true), 'client_user_agent'))
            ->all();

        self::assertSame(['Passion test visitor A', 'Passion test visitor B'], $storedAgents);
    }

    public function test_repeated_delivery_attempts_keep_the_persisted_event_identifier(): void
    {
        $configuration = $this->activeConfiguration();
        $event = MetaEvent::query()->create([
            'event_name' => 'AddToCart',
            'meta_configuration_id' => $configuration->id,
            'event_time' => now(),
            'consent_policy_version' => 1,
            'marketing_consent' => true,
            'source_url' => 'http://localhost/produits/serum',
            'context_summary' => ['value_millimes' => 30_000, 'currency' => 'TND'],
            'payload_hash' => hash('sha256', 'stable-identifier'),
        ]);
        Http::fake([
            'graph.facebook.com/*' => Http::sequence()
                ->push(['error' => ['message' => 'Temporary failure']], 503)
                ->push(['events_received' => 1], 200),
        ]);

        $client = app(MetaConversionsClient::class);
        $firstAttempt = $event->fresh('configuration');
        self::assertInstanceOf(MetaEvent::class, $firstAttempt);
        self::assertFalse($client->send($firstAttempt)->accepted);

        $secondAttempt = $event->fresh('configuration');
        self::assertInstanceOf(MetaEvent::class, $secondAttempt);
        self::assertTrue($client->send($secondAttempt)->accepted);

        $identifiers = collect(Http::recorded())
            ->map(fn (array $pair): string => (string) $pair[0]->data()['data'][0]['event_id'])
            ->all();

        self::assertSame([$event->event_id, $event->event_id], $identifiers);
        $persistedEvent = $event->fresh();
        self::assertInstanceOf(MetaEvent::class, $persistedEvent);
        self::assertSame($event->event_id, $persistedEvent->event_id);
    }

    private function activeConfiguration(): MetaConfiguration
    {
        return MetaConfiguration::query()->create([
            'configuration_version' => ((int) MetaConfiguration::query()->max('configuration_version')) + 1,
            'state' => 'active',
            'tracking_enabled' => true,
            'pixel_id' => '1234567890',
            'capi_access_token_encrypted' => Crypt::encryptString('test-capi-token'),
            'activated_at' => now(),
        ]);
    }

    private function grantMarketingConsent(): void
    {
        app()->instance(MarketingConsentService::class, new class extends MarketingConsentService
        {
            public function __construct() {}

            public function hasCurrentMarketingConsent(Request $request): bool
            {
                return true;
            }

            /** @return array{necessary: true, marketing: true, policy_version: 1, decided: true} */
            public function current(Request $request): array
            {
                return ['necessary' => true, 'marketing' => true, 'policy_version' => 1, 'decided' => true];
            }
        });
    }
}
