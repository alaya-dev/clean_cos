<?php

namespace Tests\Feature\StoreManagement;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Checkout\Services\ShippingCalculator;
use App\Domain\Commerce\Actions\CreateGuestOrderAction;
use App\Domain\Commerce\Actions\ReconcileOrderItemsAction;
use App\Domain\Commerce\Models\CheckoutField;
use App\Domain\Commerce\Models\Order;
use App\Domain\Promotions\Exceptions\PromoCodeUnavailable;
use App\Domain\Promotions\Models\PromoCode;
use App\Domain\Promotions\Services\PromoCodeService;
use App\Domain\Settings\Services\StoreSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PromotionShippingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    public function test_promo_availability_rules_and_exact_usage_boundary(): void
    {
        $service = app(PromoCodeService::class);
        $valid = $this->promo(['code' => ' été-20 ', 'discount_percentage' => 20, 'minimum_subtotal_millimes' => 10_000]);
        $this->assertSame('ÉTÉ-20', $valid->code);
        $this->assertSame(2_000, $service->quote('été-20', 10_000)['discount_millimes']);

        foreach ([
            $this->promo(['code' => 'INACTIF', 'is_active' => false]),
            $this->promo(['code' => 'TROP-TOT', 'starts_at' => now()->addHour()]),
            $this->promo(['code' => 'TROP-TARD', 'ends_at' => now()->subHour()]),
            $this->promo(['code' => 'MINIMUM', 'minimum_subtotal_millimes' => 10_001]),
            $this->promo(['code' => 'EPUISE', 'usage_limit' => 1, 'usage_count' => 1]),
        ] as $promo) {
            try {
                $service->quote($promo->code, 10_000);
                $this->fail('Le code indisponible doit être refusé.');
            } catch (PromoCodeUnavailable) {
                $this->assertTrue(true);
            }
        }

        try {
            $service->quote('INCONNU', 10_000);
            $this->fail('Le code inconnu doit être refusé.');
        } catch (PromoCodeUnavailable) {
            $this->assertTrue(true);
        }
    }

    public function test_final_promo_usage_is_serialized_and_cannot_be_oversubscribed(): void
    {
        $actor = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        app(StoreSettings::class)->update(['checkout.promo_field_visible' => true], $actor->id);
        $promo = $this->promo(['code' => 'DERNIER', 'usage_limit' => 1]);
        $product = $this->product(3, 20_000);
        $payload = $this->payload($product) + ['promo_code' => 'DERNIER'];

        $this->withHeader('Idempotency-Key', '4af95712-4d91-4c57-8d29-917324205001')->postJson('/api/v1/public/orders', $payload)->assertCreated();
        $this->withHeader('Idempotency-Key', '4af95712-4d91-4c57-8d29-917324205002')->postJson('/api/v1/public/orders', $payload)
            ->assertUnprocessable()->assertJsonPath('code', 'PROMO_CODE_INVALID');

        $this->assertSame(1, $promo->fresh()->usage_count);
        $this->assertSame(1, Order::query()->count());
    }

    public function test_hidden_and_invalid_promo_responses_are_generic(): void
    {
        $product = $this->product();
        $body = ['items' => [['product_public_id' => $product->public_id, 'variant_public_id' => null, 'quantity' => 1]], 'promo_code' => 'SECRET'];

        $hidden = $this->postJson('/api/v1/public/cart/quote', $body)->assertUnprocessable();
        $actor = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        app(StoreSettings::class)->update(['checkout.promo_field_visible' => true], $actor->id);
        $invalid = $this->postJson('/api/v1/public/cart/quote', $body)->assertUnprocessable();

        $this->assertSame('Code promo invalide ou indisponible.', $hidden->json('message'));
        $this->assertSame($hidden->json('message'), $invalid->json('message'));
    }

    public function test_shipping_rule_is_identical_for_quote_checkout_persistence_and_order_editing(): void
    {
        $actor = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        app(StoreSettings::class)->update([
            'shipping.fixed_fee_millimes' => 7_000,
            'shipping.free_threshold_enabled' => true,
            'shipping.free_threshold_millimes' => 20_000,
        ], $actor->id);
        $calculator = app(ShippingCalculator::class);
        $this->assertSame(7_000, $calculator->calculate(19_999)['fee']['millimes']);
        $this->assertSame(0, $calculator->calculate(20_000)['fee']['millimes']);
        $this->assertSame(0, $calculator->calculate(20_001)['fee']['millimes']);

        $product = $this->product(5, 10_000);
        $quote = $this->postJson('/api/v1/public/cart/quote', ['items' => [['product_public_id' => $product->public_id, 'variant_public_id' => null, 'quantity' => 2]]])->assertOk();
        $orderResponse = $this->withHeader('Idempotency-Key', '4af95712-4d91-4c57-8d29-917324205003')->postJson('/api/v1/public/orders', $this->payload($product, 2))->assertCreated();
        $order = Order::query()->where('public_reference', $orderResponse->json('data.order.public_reference'))->firstOrFail();

        $this->assertSame($quote->json('data.pricing.shipping.fee.millimes'), $order->shipping_fee_millimes);
        $this->assertSame($order->shipping_fee_millimes, $orderResponse->json('data.order.pricing.shipping_fee.millimes'));

        $updated = app(ReconcileOrderItemsAction::class)->handle($order, $order->lock_version, [['product_public_id' => $product->public_id, 'variant_public_id' => null, 'quantity' => 1]], $actor->id);
        $this->assertSame(7_000, $updated->shipping_fee_millimes);
    }

    public function test_shipping_can_be_disabled_and_admin_authorization_and_cache_invalidation_are_enforced(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $superAdmin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/settings/shipping')->assertForbidden();
        $this->actingAs($superAdmin, 'sanctum')->getJson('/api/v1/admin/settings/shipping')->assertOk();

        Cache::put('pc:cache:storefront:home', ['stale' => true], 60);
        $this->actingAs($superAdmin, 'sanctum')->patchJson('/api/v1/admin/settings/shipping', [
            'fixed_fee_millimes' => 4_000, 'free_threshold_enabled' => false, 'free_threshold_millimes' => null,
        ])->assertOk();
        $this->assertFalse(Cache::has('pc:cache:storefront:home'));
        $this->assertSame(4_000, app(ShippingCalculator::class)->calculate(999_999)['fee']['millimes']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'settings.shipping_updated']);
    }

    public function test_store_settings_reads_the_cached_value_before_the_persisted_setting(): void
    {
        $actor = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $settings = app(StoreSettings::class);
        $key = 'pc:cache:settings:v2:store.footer_statement';
        $settings->update(['store.footer_statement' => 'Valeur persistée'], $actor->id);

        try {
            Cache::forever($key, ['value' => 'Valeur du cache']);

            $this->assertSame('Valeur du cache', app(StoreSettings::class)->get('store.footer_statement'));
        } finally {
            Cache::forget($key);
        }
    }

    public function test_referenced_promo_is_deleted_and_historical_snapshot_is_preserved(): void
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        app(StoreSettings::class)->update(['checkout.promo_field_visible' => true], $superAdmin->id);
        $promo = $this->promo(['code' => 'HISTOIRE']);
        $product = $this->product();
        $payload = $this->payload($product) + ['promo_code' => 'HISTOIRE'];
        $this->withHeader('Idempotency-Key', '4af95712-4d91-4c57-8d29-917324205004')->postJson('/api/v1/public/orders', $payload)->assertCreated();

        $this->actingAs($superAdmin, 'sanctum')->deleteJson('/api/v1/admin/promo-codes/'.$promo->public_id)->assertOk();

        $order = Order::query()->firstOrFail();
        $this->assertDatabaseMissing('promo_codes', ['id' => $promo->id]);
        $this->assertNull($order->fresh()->promo_code_id);
        $this->assertSame('HISTOIRE', $order->promo_code_snapshot['code']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'promotions.promo_deleted']);
    }

    public function test_super_admin_can_activate_and_deactivate_a_promo_code(): void
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $promo = $this->promo(['is_active' => false]);

        $this->actingAs($superAdmin, 'sanctum')->postJson('/api/v1/admin/promo-codes/'.$promo->public_id.'/status', ['is_active' => true])
            ->assertOk()
            ->assertJsonPath('data.is_active', true);

        $this->actingAs($superAdmin, 'sanctum')->postJson('/api/v1/admin/promo-codes/'.$promo->public_id.'/status', ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('audit_logs', ['action' => 'promotions.promo_activated']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'promotions.promo_deactivated']);
    }

    /** @param array<string, mixed> $overrides */
    private function promo(array $overrides = []): PromoCode
    {
        return PromoCode::query()->forceCreate($overrides + ['code' => 'PROMO', 'discount_percentage' => 10, 'usage_limit' => 10, 'usage_count' => 0, 'is_active' => true]);
    }

    private function product(int $stock = 5, int $price = 20_000): Product
    {
        $category = Category::query()->create(['name' => 'Soin '.str()->random(4), 'slug' => 'soin-'.str()->random(8), 'is_active' => true]);

        return Product::query()->create(['category_id' => $category->id, 'name' => 'Produit', 'slug' => 'produit-'.str()->random(8), 'regular_price_millimes' => $price, 'stock_quantity' => $stock, 'is_active' => true, 'has_variants' => false, 'published_at' => now()]);
    }

    /** @return array<string, mixed> */
    private function payload(Product $product, int $quantity = 1): array
    {
        $fields = CheckoutField::query()->where('is_active', true)->orderBy('sort_order')->get()->map(fn (CheckoutField $field) => $field->only(['key', 'label', 'type', 'is_required', 'options', 'sort_order']))->all();

        return ['checkout_schema_version' => app(CreateGuestOrderAction::class)->schemaVersion($fields), 'customer' => ['full_name' => 'Cliente Test', 'phone' => '22123456', 'city' => 'Tunis', 'governorate' => 'Tunis', 'address' => '10 rue des Jasmins'], 'items' => [['product_public_id' => $product->public_id, 'variant_public_id' => null, 'quantity' => $quantity]]];
    }
}
