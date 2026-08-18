<?php

namespace App\Http\Resources;

use App\Domain\Checkout\Services\ShippingCalculator;
use App\Domain\Commerce\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Order */
class PublicOrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $shippingCalculator = app(ShippingCalculator::class);

        return [
            'public_reference' => $this->public_reference,
            'status' => $this->status,
            'customer' => [
                'full_name' => $this->customer_name,
                'phone' => $this->customer_phone,
                'city' => $this->customer_city,
                'address' => $this->customer_address,
            ],
            'items' => $this->items->map(function ($item): array {
                return [
                    'product_name' => $item->product_name_snapshot,
                    'variant' => $item->variant_snapshot,
                    'quantity' => $item->quantity,
                    'effective_unit_price' => [
                        'millimes' => $item->effective_unit_price_millimes,
                        'formatted' => number_format($item->effective_unit_price_millimes / 1000, 3, ',', ' ').' TND',
                    ],
                    'line_total' => [
                        'millimes' => $item->line_total_millimes,
                        'formatted' => number_format($item->line_total_millimes / 1000, 3, ',', ' ').' TND',
                    ],
                ];
            })->values(),
            'checkout_snapshot' => $this->checkoutValues->map(function ($value): array {
                return [
                    'field_key' => $value->field_key_snapshot,
                    'label' => $value->label_snapshot,
                    'type' => $value->type_snapshot,
                    'is_required' => $value->is_required_snapshot,
                    'value' => $value->value,
                ];
            })->values(),
            'pricing' => [
                'subtotal' => ['millimes' => $this->subtotal_millimes, 'formatted' => number_format($this->subtotal_millimes / 1000, 3, ',', ' ').' TND'],
                'product_discount' => ['millimes' => $this->product_discount_millimes, 'formatted' => number_format($this->product_discount_millimes / 1000, 3, ',', ' ').' TND'],
                'promo_code_discount' => ['millimes' => $this->promo_code_discount_millimes, 'formatted' => number_format($this->promo_code_discount_millimes / 1000, 3, ',', ' ').' TND'],
                'shipping_fee' => $shippingCalculator->money((int) $this->shipping_fee_millimes, (int) $this->shipping_fee_millimes === 0),
                'total' => ['millimes' => $this->total_millimes, 'formatted' => number_format($this->total_millimes / 1000, 3, ',', ' ').' TND'],
            ],
            'payment_method' => 'cash_on_delivery',
            'created_at' => optional($this->created_at)?->toIso8601String(),
        ];
    }
}
