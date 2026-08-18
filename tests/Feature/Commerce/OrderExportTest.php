<?php

namespace Tests\Feature\Commerce;

use App\Domain\Commerce\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_is_private_and_contains_only_the_operational_columns(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $order = Order::query()->create([
            'checkout_idempotency_key' => (string) str()->uuid(),
            'checkout_payload_hash' => str()->random(64),
            'status' => 'nouvelle',
            'customer_name' => 'Client Export',
            'customer_phone' => '22123456',
            'customer_city' => 'Tunis',
            'customer_address' => 'Adresse privée à ne pas exporter',
            'subtotal_millimes' => 10_000,
            'product_discount_millimes' => 0,
            'promo_code_discount_millimes' => 0,
            'shipping_fee_millimes' => 0,
            'total_millimes' => 10_000,
        ]);

        $response = $this->actingAs($admin, 'sanctum')->get('/api/v1/admin/orders/export');

        $response->assertOk();
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $csv = $response->streamedContent();
        $this->assertStringContainsString($order->public_reference, $csv);
        $this->assertStringNotContainsString('Adresse privée', $csv);
    }
}
