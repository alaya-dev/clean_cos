<?php

namespace Tests\Feature\Commerce;

use App\Domain\Commerce\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class OrderConfirmationTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirmation_requires_a_valid_unexpired_signature_without_pii_in_the_url(): void
    {
        $order = $this->order();
        $url = URL::temporarySignedRoute('storefront.confirmation', now()->addDay(), ['order' => $order]);

        $this->assertStringNotContainsString('22 123 456', $url);
        $this->get($url)->assertOk()->assertSee($order->public_reference)->assertSee('noindex,nofollow', false);
        $this->get(URL::temporarySignedRoute('storefront.confirmation', now()->subMinute(), ['order' => $order]))->assertForbidden();
    }

    private function order(): Order
    {
        return Order::query()->create(['checkout_idempotency_key' => '4af95712-4d91-4c57-8d29-917324200003', 'status' => 'nouvelle', 'customer_name' => 'Client Test', 'customer_phone' => '22 123 456', 'customer_city' => 'Tunis', 'customer_address' => '10 rue de la Paix', 'subtotal_millimes' => 10_000, 'product_discount_millimes' => 0, 'promo_code_discount_millimes' => 0, 'shipping_fee_millimes' => 0, 'total_millimes' => 10_000]);
    }
}
