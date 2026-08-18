<?php

namespace Tests\Feature\Commerce;

use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\OrderChangeEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrderChangeFeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_change_feed_requires_an_active_catalog_admin(): void
    {
        $this->getJson('/api/v1/admin/orders/changes')->assertUnauthorized();

        $inactive = User::factory()->create(['role' => 'admin', 'is_active' => false]);
        $this->actingAs($inactive, 'sanctum')->getJson('/api/v1/admin/orders/changes')->assertForbidden();
    }

    public function test_an_unchanged_feed_returns_only_a_cursor_and_no_sensitive_data(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $order = $this->makeOrder();

        $listResponse = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/orders')
            ->assertOk()
            ->assertJsonPath('meta.new_orders_count', 1);
        $cursor = $listResponse->json('meta.order_changes_cursor');

        $queryCount = 0;
        DB::listen(static function () use (&$queryCount): void {
            $queryCount++;
        });
        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/orders/changes?cursor='.urlencode($cursor));

        $response->assertOk()
            ->assertJsonPath('data.changed', false)
            ->assertJsonMissingPath('data.created_ids')
            ->assertJsonMissingPath('data.customer_phone')
            ->assertJsonMissingPath('data.customer_address');
        $this->assertNotEmpty($cursor);
        $this->assertNotNull($order->fresh());
        $this->assertLessThanOrEqual(3, $queryCount);
    }

    public function test_created_updated_and_deleted_orders_are_detected_by_a_monotonic_cursor(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $first = $this->makeOrder();
        $cursor = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/orders')->json('meta.order_changes_cursor');

        $created = $this->makeOrder();
        $first->update(['status' => 'confirmee']);
        $created->delete();

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/orders/changes?cursor='.urlencode($cursor));

        $response->assertOk()
            ->assertJsonPath('data.changed', true)
            ->assertJsonPath('data.created_ids.0', $created->public_reference)
            ->assertJsonPath('data.updated_ids.0', $first->public_reference)
            ->assertJsonPath('data.deleted_ids.0', $created->public_reference);
        $this->assertGreaterThan((int) json_decode(base64_decode(strtr($cursor, '-_', '+/')), true)['sequence'], (int) json_decode(base64_decode(strtr($response->json('data.cursor'), '-_', '+/')), true)['sequence']);
    }

    public function test_invalid_cursors_are_rejected_without_querying_order_details(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/orders/changes?cursor=not-a-cursor')
            ->assertStatus(422)
            ->assertJsonPath('code', 'INVALID_ORDER_CHANGE_CURSOR');
    }

    public function test_events_with_the_same_timestamp_are_still_ordered_by_sequence(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $timestamp = now();
        $first = OrderChangeEvent::query()->create(['order_public_reference' => '01TESTFIRST', 'change_type' => 'created', 'created_at' => $timestamp]);
        $cursor = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/orders')->json('meta.order_changes_cursor');
        OrderChangeEvent::query()->create(['order_public_reference' => '01TESTSECOND', 'change_type' => 'created', 'created_at' => $timestamp]);

        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/orders/changes?cursor='.urlencode($cursor))
            ->assertOk()
            ->assertJsonPath('data.created_ids.0', '01TESTSECOND');
        $this->assertNotNull($first->fresh());
    }

    public function test_the_tentative_status_group_filter_returns_all_three_tentative_statuses(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->makeOrder('tentative_1');
        $this->makeOrder('tentative_2');
        $this->makeOrder('tentative_3');
        $this->makeOrder('confirmee');

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/orders?statuses[]=tentative_1&statuses[]=tentative_2&statuses[]=tentative_3&per_page=100');

        $response->assertOk()->assertJsonPath('data.total', 3);
        $this->assertSame(['tentative_1', 'tentative_2', 'tentative_3'], collect($response->json('data.data'))->pluck('status')->sort()->values()->all());
    }

    public function test_the_change_feed_has_a_bounded_admin_rate_limit(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $last = null;
        for ($attempt = 0; $attempt < 121; $attempt++) {
            $last = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/orders/changes');
        }

        $last?->assertStatus(429);
    }

    private function makeOrder(string $status = 'nouvelle'): Order
    {
        return Order::query()->create([
            'checkout_idempotency_key' => (string) str()->uuid(),
            'checkout_payload_hash' => str()->random(64),
            'status' => $status,
            'lock_version' => 1,
            'customer_name' => 'Client test',
            'customer_phone' => '22123456',
            'customer_city' => 'Tunis',
            'customer_address' => 'Rue test',
            'subtotal_millimes' => 10_000,
            'product_discount_millimes' => 0,
            'promo_code_discount_millimes' => 0,
            'shipping_fee_millimes' => 0,
            'total_millimes' => 10_000,
        ]);
    }
}
