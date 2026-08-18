<?php

namespace Tests\Feature\Commerce;

use App\Domain\Commerce\Models\Customer;
use App\Domain\Commerce\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CustomerHistoryBackfillCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_links_historical_orders_and_is_idempotent(): void
    {
        $first = $this->createOrder('+216 27 123 456', 'Premier client', Carbon::now()->subDays(10));
        $second = $this->createOrder('27123456', 'Client actualisé', Carbon::now()->subDays(2));
        $this->createOrder('not-a-phone', 'Sans téléphone', Carbon::now()->subDay());

        $this->artisan('customers:backfill-from-orders')->assertSuccessful();

        $profile = Customer::query()->sole();
        $this->assertSame('27123456', $profile->phone_normalized);
        $this->assertSame(2, $profile->orders_count);
        $this->assertSame('Client actualisé', $profile->name);
        $this->assertSame($profile->id, $first->fresh()->customer_id);
        $this->assertSame($profile->id, $second->fresh()->customer_id);
        $this->assertTrue($second->fresh()->customer_previous_order_at?->equalTo($first->created_at));

        $this->artisan('customers:backfill-from-orders')->assertSuccessful();
        $this->assertSame(1, Customer::query()->count());
        $this->assertSame(2, Customer::query()->sole()->orders_count);
        $this->assertTrue($second->fresh()->customer_previous_order_at?->equalTo($first->created_at));
    }

    public function test_dry_run_does_not_create_profiles_or_change_orders(): void
    {
        $order = $this->createOrder('27123456', 'Client', Carbon::now()->subDay());

        $this->artisan('customers:backfill-from-orders', ['--dry-run' => true])->assertSuccessful();

        $this->assertDatabaseCount('customers', 0);
        $this->assertNull($order->fresh()->customer_id);
        $this->assertNull($order->fresh()->customer_previous_order_at);
    }

    private function createOrder(string $phone, string $name, Carbon $createdAt): Order
    {
        $order = Order::query()->create([
            'checkout_idempotency_key' => (string) str()->uuid(),
            'status' => 'nouvelle',
            'customer_name' => $name,
            'customer_phone' => $phone,
            'customer_city' => 'Tunis',
            'customer_address' => 'Rue test',
            'subtotal_millimes' => 10_000,
            'product_discount_millimes' => 0,
            'promo_code_discount_millimes' => 0,
            'shipping_fee_millimes' => 0,
            'total_millimes' => 10_000,
        ]);
        $order->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->saveQuietly();

        return $order->fresh();
    }
}
