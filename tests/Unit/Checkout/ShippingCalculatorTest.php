<?php

namespace Tests\Unit\Checkout;

use App\Domain\Checkout\Services\ShippingCalculator;
use App\Domain\Settings\Services\StoreSettings;
use Tests\TestCase;

class ShippingCalculatorTest extends TestCase
{
    public function test_shipping_fee_changes_at_threshold(): void
    {
        $settings = new class extends StoreSettings
        {
            public function get(string $key): mixed
            {
                return match ($key) {
                    'shipping.fixed_fee_millimes' => 3_000,
                    'shipping.free_threshold_enabled' => true,
                    'shipping.free_threshold_millimes' => 20_000,
                };
            }
        };
        $calculator = new ShippingCalculator($settings);

        $below = $calculator->calculate(19_999);
        $at = $calculator->calculate(20_000);
        $above = $calculator->calculate(20_001);

        $this->assertFalse($below['is_free']);
        $this->assertSame(3_000, $below['fee']['millimes']);
        $this->assertTrue($at['is_free']);
        $this->assertTrue($above['is_free']);
    }
}
