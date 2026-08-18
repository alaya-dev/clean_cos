<?php

namespace App\Domain\Commerce\Actions;

use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Services\OrderExchangeDetails;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateOrderCustomerAction
{
    public function __construct(private readonly OrderExchangeDetails $exchangeDetails) {}

    /** @param array{full_name: string, phone: string, city: string, governorate?: string|null, address: string} $customer
     * @param  array<string, mixed>|null  $exchange
     */
    public function handle(Order $order, int $lockVersion, array $customer, ?array $exchange = null): Order
    {
        return DB::transaction(function () use ($order, $lockVersion, $customer, $exchange): Order {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($order->lock_version !== $lockVersion) {
                throw ValidationException::withMessages(['lock_version' => 'La commande a été modifiée.']);
            }
            $phone = preg_replace('/[^0-9+]/', '', $customer['phone']) ?? $customer['phone'];
            $attributes = ['customer_name' => trim($customer['full_name']), 'customer_phone' => $phone, 'customer_city' => trim($customer['city']), 'customer_governorate' => filled($customer['governorate'] ?? null) ? trim((string) $customer['governorate']) : null, 'customer_address' => trim($customer['address']), 'lock_version' => $order->lock_version + 1];
            if ($exchange !== null) {
                $attributes = [...$attributes, ...$this->exchangeDetails->normalize($exchange)];
            }
            $order->update($attributes);

            return $order->fresh() ?? $order;
        });
    }
}
