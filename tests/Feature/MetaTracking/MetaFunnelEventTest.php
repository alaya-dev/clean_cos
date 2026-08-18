<?php

namespace Tests\Feature\MetaTracking;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\MetaTracking\Models\MetaConfiguration;
use App\Domain\MetaTracking\Models\MetaEvent;
use App\Domain\MetaTracking\Services\MarketingConsentService;
use App\Jobs\DispatchMetaEventJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MetaFunnelEventTest extends TestCase
{
    use RefreshDatabase;

    public function test_funnel_events_are_not_created_without_current_marketing_consent(): void
    {
        $this->activeConfiguration();

        $this->postJson('/api/v1/public/meta/events', [
            'event_name' => 'Search',
            'source_url' => 'http://localhost/recherche?q=serum',
            'search_term' => 'Sérum',
            'result_count' => 1,
        ])->assertOk()->assertJsonPath('data.event', null);

        $this->assertDatabaseCount('meta_events', 0);
    }

    public function test_consented_add_to_cart_uses_server_authoritative_product_value_and_queues_delivery(): void
    {
        Queue::fake();
        $this->activeConfiguration();
        $this->grantMarketingConsent();
        $category = Category::query()->create(['name' => 'Soins', 'slug' => 'soins', 'is_active' => true]);
        $product = Product::query()->create([
            'category_id' => $category->id,
            'name' => 'Sérum actif',
            'slug' => 'serum-actif',
            'regular_price_millimes' => 30_000,
            'promotional_price_millimes' => 25_000,
            'stock_quantity' => 8,
            'is_active' => true,
            'has_variants' => false,
            'published_at' => now(),
        ]);

        $response = $this->postJson('/api/v1/public/meta/events', [
            'event_name' => 'AddToCart',
            'source_url' => 'http://localhost/produits/serum-actif',
            'product_public_id' => $product->public_id,
            'quantity' => 2,
        ])
            ->assertOk()
            ->assertJsonPath('data.event.event_name', 'AddToCart')
            ->assertJsonPath('data.event.context.value_millimes', 50_000);

        $event = MetaEvent::query()->firstOrFail();
        $this->assertSame('AddToCart', $event->event_name);
        $this->assertSame(50_000, $event->context_summary['value_millimes']);
        $this->assertNotSame('', $event->event_id);
        Queue::assertPushed(DispatchMetaEventJob::class, fn (DispatchMetaEventJob $job): bool => $job->eventPublicId === $event->public_id);
        $this->assertSame($event->event_id, $response->json('data.event.event_id'));
        $this->assertSame($event->public_id, $response->json('data.event.public_id'));

        $this->postJson('/api/v1/public/meta/events/'.$event->public_id.'/browser-attempt')
            ->assertOk()
            ->assertJsonPath('data.recorded', true);
        $this->assertDatabaseHas('meta_events', ['id' => $event->id, 'browser_state' => 'attempted']);
    }

    public function test_normal_event_preserves_local_port_strips_query_and_encrypts_server_match_data(): void
    {
        Queue::fake();
        $this->activeConfiguration();
        $this->grantMarketingConsent();

        $this->withHeader('User-Agent', 'Passion browser test')
            ->postJson('/api/v1/public/meta/events', [
                'event_name' => 'PageView',
                'source_url' => 'http://localhost:8000/?utm_source=sensitive',
                'route_type' => 'home',
            ])->assertOk()->assertJsonPath('data.event.event_name', 'PageView');

        $event = MetaEvent::query()->firstOrFail();
        self::assertSame('http://localhost:8000/', $event->source_url);
        self::assertNotNull($event->user_data_encrypted);
        $userData = json_decode(Crypt::decryptString($event->user_data_encrypted), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('Passion browser test', $userData['client_user_agent']);
        self::assertArrayHasKey('client_ip_address', $userData);
    }

    private function activeConfiguration(): void
    {
        MetaConfiguration::query()->create([
            'configuration_version' => ((int) MetaConfiguration::query()->max('configuration_version')) + 1,
            'state' => 'active',
            'tracking_enabled' => true,
            'pixel_id' => '1234567890',
            'capi_access_token_encrypted' => Crypt::encryptString('test-token'),
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

            public function current(Request $request): array
            {
                return ['necessary' => true, 'marketing' => true, 'policy_version' => 1, 'decided' => true];
            }
        });
    }
}
