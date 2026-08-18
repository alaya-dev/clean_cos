<?php

namespace Tests\Integration\Database;

use App\Domain\Commerce\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ArchivedOrderMigrationSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_populated_archived_order_retains_timestamp_and_column(): void
    {
        $archivedAt = now()->subDay()->startOfSecond();
        $order = Order::query()->create(['checkout_idempotency_key' => (string) str()->uuid(), 'checkout_payload_hash' => str()->random(64), 'status' => 'confirmee', 'customer_name' => 'Client', 'customer_phone' => '22123456', 'customer_city' => 'Tunis', 'customer_address' => 'Rue', 'subtotal_millimes' => 1000, 'product_discount_millimes' => 0, 'promo_code_discount_millimes' => 0, 'shipping_fee_millimes' => 0, 'total_millimes' => 1000, 'archived_at' => $archivedAt]);
        self::assertTrue(Schema::hasColumn('orders', 'archived_at'));
        self::assertSame($archivedAt->toDateTimeString(), $order->fresh()->archived_at->toDateTimeString());
        $migration = file_get_contents(base_path('database/migrations/2026_07_18_000700_add_archived_at_to_orders.php'));
        self::assertStringContainsString('forward', $migration);
        self::assertStringContainsString('migration', $migration);
    }
}
