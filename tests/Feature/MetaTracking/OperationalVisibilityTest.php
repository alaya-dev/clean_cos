<?php

namespace Tests\Feature\MetaTracking;

use App\Domain\Commerce\Models\CheckoutDraft;
use App\Domain\Commerce\Models\Order;
use App\Domain\MetaTracking\Models\MetaConfiguration;
use App\Domain\MetaTracking\Models\MetaEvent;
use App\Domain\Navex\Enums\NavexDeliveryStatus;
use App\Domain\Navex\Models\NavexShipment;
use App\Jobs\DispatchMetaEventJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class OperationalVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_operational_dashboard_without_diagnostic_detail(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $order = $this->order('confirmee');
        NavexShipment::query()->create(['order_id' => $order->id, 'status' => NavexDeliveryStatus::DeliveredPaid, 'creation_mode' => 'automatic']);
        CheckoutDraft::query()->create(['customer_data' => ['phone' => '20123456'], 'cart_snapshot' => [], 'last_activity_at' => now()->subMinutes(20)]);
        $this->actingAs($admin)->getJson('/api/v1/admin/dashboard?period=7d')
            ->assertOk()
            ->assertJsonPath('data.orders.submitted', 1)
            ->assertJsonPath('data.orders.delivered_revenue_millimes', 40_000)
            ->assertJsonPath('data.orders.summary.week.orders', 1)
            ->assertJsonStructure(['data' => ['orders' => ['summary', 'trend', 'by_status']]])
            ->assertJsonMissingPath('data.meta')
            ->assertJsonMissingPath('data.inventory')
            ->assertJsonMissingPath('data.complaints')
            ->assertJsonFragment(['drafts' => 1]);
        $this->actingAs($admin)->getJson('/api/v1/admin/meta/diagnostics')->assertForbidden();
    }

    public function test_admin_can_view_safe_operational_health_and_a_bounded_custom_period(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $this->actingAs($admin)->getJson('/api/v1/admin/operational-health')
            ->assertOk()
            ->assertJsonStructure(['data' => ['state', 'cache', 'failed_queue_jobs', 'pending_meta_events', 'scheduler']])
            ->assertJsonMissing(['capi_access_token_encrypted']);
        $this->actingAs($admin)->getJson('/api/v1/admin/dashboard?period=custom&date_from='.now()->subDays(2)->toDateString().'&date_to='.now()->toDateString())
            ->assertOk()
            ->assertJsonPath('data.period', 'custom');
    }

    public function test_sales_tab_metrics_include_only_confirmed_orders_and_reconcile_product_and_shipping_amounts(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-26 12:00:00', 'Africa/Tunis'));
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $today = now();
        $this->salesOrder('confirmee', 120_000, 20_000, $today);
        $this->salesOrder('confirmee', 100_000, 10_000, $today->copy()->subDay());
        $this->salesOrder('confirmee', 300_000, 30_000, $today->copy()->subMonth());
        $this->salesOrder('nouvelle', 500_000, 50_000, $today);
        $this->salesOrder('annulee', 600_000, 60_000, $today);

        $this->actingAs($admin)->getJson('/api/v1/admin/dashboard?period=7d')
            ->assertOk()
            ->assertJsonPath('data.sales.summary.today.orders', 1)
            ->assertJsonPath('data.sales.summary.today.total_millimes', 120_000)
            ->assertJsonPath('data.sales.summary.today.product_millimes', 100_000)
            ->assertJsonPath('data.sales.summary.today.shipping_millimes', 20_000)
            ->assertJsonPath('data.sales.summary.week.orders', 2)
            ->assertJsonPath('data.sales.summary.week.total_millimes', 220_000)
            ->assertJsonPath('data.sales.summary.month.orders', 2)
            ->assertJsonPath('data.sales.summary.all.orders', 3)
            ->assertJsonPath('data.sales.summary.all.total_millimes', 520_000)
            ->assertJsonPath('data.sales.summary.all.product_millimes', 460_000)
            ->assertJsonPath('data.sales.summary.all.shipping_millimes', 60_000)
            ->assertJsonCount(7, 'data.sales.trend')
            ->assertJsonStructure(['data' => ['orders', 'sales' => ['summary' => ['today', 'week', 'month', 'all'], 'trend']]]);
        $this->actingAs($admin)->getJson('/api/v1/admin/dashboard?period=custom&date_from=2026-08-25&date_to=2026-08-26')
            ->assertOk()
            ->assertJsonPath('data.sales.summary.today.orders', 1)
            ->assertJsonCount(2, 'data.sales.trend')
            ->assertJsonPath('data.sales.trend.0.total_millimes', 100_000)
            ->assertJsonPath('data.sales.trend.1.total_millimes', 120_000);
        Carbon::setTestNow();
    }

    public function test_super_admin_can_open_safe_diagnostics_and_request_a_password_confirmed_retry(): void
    {
        Queue::fake();
        $superAdmin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $event = $this->event('Purchase', 'permanent_failure');

        $this->actingAs($superAdmin)->getJson('/api/v1/admin/meta/diagnostics')
            ->assertOk()
            ->assertJsonPath('data.data.0.public_id', $event->public_id)
            ->assertJsonMissing(['secret-capi-token']);
        $this->actingAs($superAdmin)->postJson('/api/v1/admin/meta/diagnostics/'.$event->public_id.'/retry', ['current_password' => 'password'])
            ->assertOk()
            ->assertJsonPath('data.event.capi_state', 'pending');
        Queue::assertPushed(DispatchMetaEventJob::class);
    }

    public function test_super_admin_can_filter_diagnostics_by_marketing_consent(): void
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $event = $this->event('PageView', 'skipped_no_consent', null, false);

        $this->actingAs($superAdmin)->getJson('/api/v1/admin/meta/diagnostics?marketing_consent=false')
            ->assertOk()
            ->assertJsonPath('data.data.0.public_id', $event->public_id)
            ->assertJsonPath('data.data.0.marketing_consent', false);
    }

    public function test_diagnostics_are_paginated_filterable_and_expose_only_sanitized_attempt_details(): void
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        foreach (range(1, 27) as $index) {
            $this->event($index % 2 === 0 ? 'PageView' : 'ViewContent', $index === 1 ? 'permanent_failure' : 'succeeded');
        }
        $failed = MetaEvent::query()->where('capi_state', 'permanent_failure')->firstOrFail();
        $failed->attempts()->create([
            'channel' => 'capi', 'attempt_number' => 1, 'outcome' => 'permanent_failure',
            'request_sent' => true, 'http_status' => 400, 'events_received' => 0,
            'error_classification' => 'meta_rejected', 'meta_error_code' => '100',
            'safe_message' => 'Invalid parameter', 'graph_api_version' => 'v25.0', 'attempted_at' => now(),
        ]);

        $this->actingAs($superAdmin)->getJson('/api/v1/admin/meta/diagnostics?event_name=PageView&per_page=5')
            ->assertOk()->assertJsonPath('data.meta.current_page', 1)
            ->assertJsonPath('data.meta.last_page', 3)->assertJsonCount(5, 'data.data');
        $this->actingAs($superAdmin)->getJson('/api/v1/admin/meta/diagnostics/'.$failed->public_id)
            ->assertOk()->assertJsonPath('data.attempts.0.request_sent', true)
            ->assertJsonPath('data.attempts.0.error_classification', 'meta_rejected')
            ->assertJsonPath('data.attempts.0.graph_api_version', 'v25.0')
            ->assertJsonMissing(['secret-capi-token', 'customer_phone', 'test_event_code']);
    }

    private function order(string $status): Order
    {
        return Order::query()->create([
            'checkout_idempotency_key' => (string) Str::uuid(), 'checkout_payload_hash' => hash('sha256', Str::random()), 'status' => $status,
            'customer_name' => 'Client test', 'customer_phone' => '20123456', 'customer_city' => 'Tunis', 'customer_address' => 'Adresse test',
            'subtotal_millimes' => 40_000, 'product_discount_millimes' => 0, 'promo_code_discount_millimes' => 0, 'shipping_fee_millimes' => 0, 'total_millimes' => 40_000,
        ]);
    }

    private function salesOrder(string $status, int $total, int $shipping, \DateTimeInterface $createdAt): Order
    {
        $order = $this->order($status);
        $order->forceFill(['total_millimes' => $total, 'shipping_fee_millimes' => $shipping, 'created_at' => $createdAt, 'updated_at' => $createdAt])->saveQuietly();

        return $order;
    }

    private function event(string $name, string $state, ?Order $order = null, bool $marketingConsent = true): MetaEvent
    {
        $configuration = MetaConfiguration::query()->firstOrCreate(['configuration_version' => 1, 'state' => 'active'], ['tracking_enabled' => true, 'pixel_id' => '1234567890', 'capi_access_token_encrypted' => Crypt::encryptString('secret-capi-token'), 'activated_at' => now()]);
        $order ??= $this->order('confirmee');

        return MetaEvent::query()->create(['event_name' => $name, 'order_id' => $order->id, 'meta_configuration_id' => $configuration->id, 'event_time' => now(), 'consent_policy_version' => 1, 'marketing_consent' => $marketingConsent, 'source_url' => 'https://passion.test/confirmation', 'context_summary' => ['value_millimes' => 40_000, 'currency' => 'TND'], 'payload_hash' => hash('sha256', Str::random()), 'capi_state' => $state, 'browser_state' => 'attempted']);
    }
}
