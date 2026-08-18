<?php

namespace Tests\Feature\MetaTracking;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\MetaTracking\Models\MetaConfiguration;
use App\Domain\MetaTracking\Models\MetaEvent;
use App\Domain\MetaTracking\Services\MarketingConsentService;
use App\Domain\MetaTracking\Services\MetaAttributionContextFactory;
use App\Domain\MetaTracking\Services\MetaConversionsClient;
use App\Http\Middleware\EncryptCookies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MetaAttributionContextTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        Cache::forget('pc:cache:settings:v2:shipping.fixed_fee_millimes');
        Cache::forget('pc:cache:settings:v2:shipping.free_threshold_enabled');
        Cache::forget('pc:cache:settings:v2:shipping.free_threshold_millimes');
    }

    public function test_existing_fbc_and_fbp_are_preserved_unchanged_in_the_request_snapshot(): void
    {
        $request = Request::create('/commande', 'POST', server: ['REMOTE_ADDR' => '203.0.113.10', 'HTTP_USER_AGENT' => 'Passion attribution browser']);
        $request->cookies->set('_fbc', 'fb.1.1710000000000.ClickId_ABC');
        $request->cookies->set('_fbp', 'fb.1.1710000000000.BrowserId_ABC');
        $snapshot = app(MetaAttributionContextFactory::class)->capture($request);

        self::assertSame('fb.1.1710000000000.ClickId_ABC', $snapshot['fbc']);
        self::assertSame('fb.1.1710000000000.BrowserId_ABC', $snapshot['fbp']);
        self::assertSame('203.0.113.10', $snapshot['client_ip_address']);
        self::assertSame('Passion attribution browser', $snapshot['client_user_agent']);
    }

    public function test_browser_owned_attribution_cookies_are_not_decrypted_by_laravel(): void
    {
        $request = Request::create('/produits/huile');
        $request->cookies->set('_fbc', 'fb.1.1710000000000.ClickId_ABC');
        $request->cookies->set('_fbp', 'fb.1.1710000000000.BrowserId_ABC');
        $snapshot = [];

        app(EncryptCookies::class)->handle($request, function (Request $request) use (&$snapshot) {
            $snapshot = app(MetaAttributionContextFactory::class)->capture($request);

            return response('ok');
        });

        self::assertSame('fb.1.1710000000000.ClickId_ABC', $snapshot['fbc']);
        self::assertSame('fb.1.1710000000000.BrowserId_ABC', $snapshot['fbp']);
    }

    public function test_valid_fbclid_creates_fbc_without_fabricating_one_for_organic_traffic(): void
    {
        $this->activeConfiguration();
        $this->grantMarketingConsent();

        $this->postJson('/api/v1/public/meta/events?fbclid=ClickId_ABC', [
            'event_name' => 'PageView',
            'source_url' => 'http://localhost/',
            'route_type' => 'home',
        ])->assertOk();
        $withClick = $this->snapshot(MetaEvent::query()->firstOrFail());
        self::assertMatchesRegularExpression('/^fb\.1\.\d+\.ClickId_ABC$/', $withClick['fbc']);

        $this->postJson('/api/v1/public/meta/events', [
            'event_name' => 'PageView',
            'source_url' => 'http://localhost/produits',
            'route_type' => 'products',
        ])->assertOk();
        $organic = $this->snapshot(MetaEvent::query()->latest('id')->firstOrFail());
        self::assertArrayNotHasKey('fbc', $organic);
    }

    public function test_click_id_cookie_persists_from_landing_to_purchase(): void
    {
        $this->activeConfiguration();
        $this->grantMarketingConsent();

        $landing = $this->get('/produits?fbclid=ClickId_ABC')->assertOk();
        $fbcCookie = collect($landing->headers->getCookies())->first(static fn ($cookie): bool => $cookie->getName() === '_fbc');

        self::assertNotNull($fbcCookie);
        $fbc = $fbcCookie->getValue();
        self::assertMatchesRegularExpression('/^fb\.1\.\d+\.ClickId_ABC$/', $fbc);

        $product = $this->product();
        $schema = $this->getJson('/api/v1/public/checkout-fields')->json('meta.schema_version');

        $this->withCredentials()->withUnencryptedCookie('_fbc', $fbc)
            ->withHeader('Idempotency-Key', '4af95712-4d91-4c57-8d29-917324200013')
            ->postJson('/api/v1/public/orders', [
                'checkout_schema_version' => $schema,
                'customer' => ['full_name' => 'Client Test', 'phone' => '22 123 456', 'city' => 'Tunis', 'governorate' => 'Tunis', 'address' => '10 rue de la Paix'],
                'items' => [['product_public_id' => $product->public_id, 'variant_public_id' => null, 'quantity' => 1]],
            ])->assertCreated();

        $event = MetaEvent::query()->where('event_name', 'Purchase')->sole();
        self::assertSame($fbc, $this->snapshot($event)['fbc']);
    }

    public function test_click_id_is_held_until_consent_then_persisted(): void
    {
        $this->activeConfiguration();

        $this->withCredentials()->withSession(['pc_meta_pending_fbclid' => 'ClickId_ABC'])
            ->postJson('/api/v1/public/marketing-consent', ['decision' => 'accept_all'])
            ->assertOk()
            ->assertCookie('_fbc');
    }

    public function test_click_id_does_not_create_a_marketing_cookie_without_consent(): void
    {
        $this->activeConfiguration();

        $this->get('/produits?fbclid=ClickId_ABC')
            ->assertOk()
            ->assertCookieMissing('_fbc');
    }

    public function test_untrusted_forwarded_ip_does_not_replace_the_direct_client_address(): void
    {
        $request = Request::create('/');
        $request->server->set('REMOTE_ADDR', '203.0.113.10');
        $request->headers->set('X-Forwarded-For', '198.51.100.10');

        $snapshot = app(MetaAttributionContextFactory::class)->capture($request);

        self::assertSame('203.0.113.10', $snapshot['client_ip_address']);
    }

    public function test_purchase_serializes_a_single_normalized_phone_hash_from_the_request_snapshot(): void
    {
        $this->activeConfiguration();
        $this->grantMarketingConsent();
        $product = $this->product();
        $schema = $this->getJson('/api/v1/public/checkout-fields')->json('meta.schema_version');
        $phone = '+216 22-123-456';
        $expectedHash = hash('sha256', '21622123456');

        $this->withHeader('Idempotency-Key', '4af95712-4d91-4c57-8d29-917324200011')
            ->withHeader('User-Agent', 'Passion purchase browser')
            ->withServerVariables(['REMOTE_ADDR' => '2001:db8::99'])
            ->postJson('/api/v1/public/orders?fbclid=ClickId_ABC', [
                'checkout_schema_version' => $schema,
                'customer' => ['full_name' => 'Client Test', 'phone' => $phone, 'city' => 'Tunis', 'governorate' => 'Tunis', 'address' => '10 rue de la Paix'],
                'items' => [['product_public_id' => $product->public_id, 'variant_public_id' => null, 'quantity' => 1]],
            ])->assertCreated();

        $event = MetaEvent::query()->where('event_name', 'Purchase')->sole();
        $snapshot = $this->snapshot($event);
        self::assertSame($expectedHash, $snapshot['ph']);
        self::assertStringNotContainsString($phone, Crypt::decryptString((string) $event->user_data_encrypted));
        $event->order?->update(['customer_phone' => '99 999 999']);

        Http::fake(['graph.facebook.com/*' => Http::sequence()
            ->push(['error' => ['message' => 'temporary']], 503)
            ->push(['events_received' => 1], 200)]);
        $client = app(MetaConversionsClient::class);
        $first = $client->send($event->fresh(['configuration', 'order']));
        $second = $client->send($event->fresh(['configuration', 'order']));
        self::assertFalse($first->accepted);
        self::assertTrue($second->accepted);

        $payloads = collect(Http::recorded())->map(fn (array $pair): array => $pair[0]->data()['data'][0])->all();
        self::assertSame($expectedHash, $payloads[0]['user_data']['ph']);
        self::assertSame($expectedHash, $payloads[1]['user_data']['ph']);
        self::assertSame($snapshot['fbc'], $payloads[0]['user_data']['fbc']);
        self::assertMatchesRegularExpression('/^fb\.1\.\d+\.ClickId_ABC$/', $payloads[0]['user_data']['fbc']);
        self::assertSame('2001:db8::99', $payloads[0]['user_data']['client_ip_address']);
        self::assertSame('Passion purchase browser', $payloads[0]['user_data']['client_user_agent']);
        self::assertSame($event->event_id, $payloads[0]['event_id']);
        self::assertSame($event->event_id, $payloads[1]['event_id']);
        self::assertStringNotContainsString($phone, json_encode($payloads, JSON_THROW_ON_ERROR));
    }

    public function test_invalid_phone_is_not_fabricated_or_sent(): void
    {
        $this->activeConfiguration();
        $this->grantMarketingConsent();
        $product = $this->product();
        $schema = $this->getJson('/api/v1/public/checkout-fields')->json('meta.schema_version');

        $response = $this->withHeader('Idempotency-Key', '4af95712-4d91-4c57-8d29-917324200012')
            ->postJson('/api/v1/public/orders', [
                'checkout_schema_version' => $schema,
                'customer' => ['full_name' => 'Client Test', 'phone' => 'not-a-phone', 'city' => 'Tunis', 'governorate' => 'Tunis', 'address' => '10 rue de la Paix'],
                'items' => [['product_public_id' => $product->public_id, 'variant_public_id' => null, 'quantity' => 1]],
            ]);

        $response->assertUnprocessable();
        self::assertSame(0, MetaEvent::query()->where('event_name', 'Purchase')->count());
    }

    /** @return array<string, string> */
    private function snapshot(MetaEvent $event): array
    {
        return json_decode(Crypt::decryptString((string) $event->user_data_encrypted), true, flags: JSON_THROW_ON_ERROR);
    }

    private function activeConfiguration(): void
    {
        MetaConfiguration::query()->create([
            'configuration_version' => 1,
            'state' => 'active',
            'tracking_enabled' => true,
            'pixel_id' => '1234567890',
            'capi_access_token_encrypted' => Crypt::encryptString('meta-test-token'),
            'activated_at' => now(),
        ]);
    }

    private function product(): Product
    {
        $category = Category::query()->create(['name' => 'Soin', 'slug' => 'soin', 'is_active' => true]);

        return Product::query()->create(['category_id' => $category->id, 'name' => 'Huile', 'slug' => 'huile', 'regular_price_millimes' => 15_000, 'stock_quantity' => 3, 'is_active' => true, 'has_variants' => false, 'published_at' => now()]);
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
