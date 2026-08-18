<?php

namespace Tests\Feature\MetaTracking;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Commerce\Models\Order;
use App\Domain\MetaTracking\Models\MarketingConsent;
use App\Domain\MetaTracking\Models\MetaConfiguration;
use App\Domain\MetaTracking\Models\MetaEvent;
use App\Jobs\DispatchMetaEventJob;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MetaPurchaseTest extends TestCase
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

    public function test_a_consented_committed_checkout_creates_one_purchase_with_safe_authoritative_values(): void
    {
        Queue::fake();
        MetaConfiguration::query()->create(['configuration_version' => 1, 'state' => 'active', 'tracking_enabled' => true, 'pixel_id' => '1234567890', 'capi_access_token_encrypted' => Crypt::encryptString('token'), 'activated_at' => now()]);
        $category = Category::query()->create(['name' => 'Soin', 'slug' => 'soin', 'is_active' => true]);
        $product = Product::query()->create(['category_id' => $category->id, 'name' => 'Huile', 'slug' => 'huile', 'regular_price_millimes' => 15_000, 'stock_quantity' => 3, 'is_active' => true, 'has_variants' => false, 'published_at' => now()]);
        $key = '4af95712-4d91-4c57-8d29-917324200011';
        $payload = ['checkout_schema_version' => $this->getJson('/api/v1/public/checkout-fields')->json('meta.schema_version'), 'customer' => ['full_name' => 'Client Test', 'phone' => '22 123 456', 'city' => 'Tunis', 'governorate' => 'Tunis', 'address' => '10 rue de la Paix'], 'items' => [['product_public_id' => $product->public_id, 'variant_public_id' => null, 'quantity' => 2]]];

        $this->withCredentials();
        $this->withMarketingConsentCookie();
        $first = $this->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/public/orders', $payload)
            ->assertCreated()
            ->assertJsonPath('data.meta.browser_event.event_name', 'Purchase')
            ->assertJsonPath('data.meta.browser_event.context.value_millimes', 30_000);
        $this->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/public/orders', $payload)
            ->assertOk()
            ->assertJsonPath('data.order.public_reference', $first->json('data.order.public_reference'));

        $order = Order::query()->firstOrFail();
        $event = MetaEvent::query()->where('order_id', $order->id)->where('event_name', 'Purchase')->firstOrFail();
        $this->assertSame(30_000, $event->context_summary['value_millimes']);
        $this->assertSame($event->event_id, $first->json('data.meta.browser_event.event_id'));
        $this->assertDatabaseCount('meta_events', 1);
        Queue::assertPushed(DispatchMetaEventJob::class, fn ($job): bool => $job->eventPublicId === $event->public_id);

        $confirmation = $this->get($first->json('data.confirmation.url'))
            ->assertOk()
            ->assertSee('data-meta-purchase', false)
            ->assertSee($event->event_id);
        $this->get($first->json('data.confirmation.url'))->assertOk();
        $this->assertDatabaseCount('meta_events', 1);
    }

    public function test_buy_now_checkout_source_is_recorded_without_changing_purchase_lifecycle(): void
    {
        Queue::fake();
        MetaConfiguration::query()->create(['configuration_version' => 1, 'state' => 'active', 'tracking_enabled' => true, 'pixel_id' => '1234567890', 'capi_access_token_encrypted' => Crypt::encryptString('token'), 'activated_at' => now()]);
        $category = Category::query()->create(['name' => 'Soin', 'slug' => 'soin-buy-now', 'is_active' => true]);
        $product = Product::query()->create(['category_id' => $category->id, 'name' => 'Huile express', 'slug' => 'huile-express', 'regular_price_millimes' => 15_000, 'stock_quantity' => 3, 'is_active' => true, 'has_variants' => false, 'published_at' => now()]);
        $payload = ['checkout_schema_version' => $this->getJson('/api/v1/public/checkout-fields')->json('meta.schema_version'), 'checkout_source' => 'buy_now', 'customer' => ['full_name' => 'Client Test', 'phone' => '22 123 456', 'city' => 'Tunis', 'governorate' => 'Tunis', 'address' => '10 rue de la Paix'], 'items' => [['product_public_id' => $product->public_id, 'variant_public_id' => null, 'quantity' => 1]]];

        $this->withCredentials();
        $this->withMarketingConsentCookie();
        $response = $this->withHeader('Idempotency-Key', '4af95712-4d91-4c57-8d29-917324200012')->postJson('/api/v1/public/orders', $payload)->assertCreated();

        $this->assertSame('buy_now', MetaEvent::query()->where('event_name', 'Purchase')->sole()->context_summary['checkout_source']);
        $response->assertJsonPath('data.meta.browser_event.event_name', 'Purchase');
    }

    private function withMarketingConsentCookie(): void
    {
        $receipt = MarketingConsent::query()->create([
            'policy_version' => 1,
            'necessary_consent' => true,
            'marketing_consent' => true,
            'decided_at' => now(),
        ]);
        $receiptPayload = Crypt::encryptString(json_encode([
            'receipt' => $receipt->public_id,
            'policy_version' => 1,
        ], JSON_THROW_ON_ERROR));
        $transportCookie = Crypt::encrypt(
            CookieValuePrefix::create('pc_marketing_consent', app('encrypter')->getKey()).$receiptPayload,
            false,
        );

        $this->withUnencryptedCookie('pc_marketing_consent', $transportCookie);
    }
}
