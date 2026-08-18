<?php

namespace App\Domain\Commerce\Services;

use App\Domain\Commerce\Models\Customer;
use App\Domain\Commerce\Models\Order;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class CustomerHistoryService
{
    public function __construct(private readonly CustomerPhoneNormalizer $phones) {}

    public function normalizePhone(string $phone): string
    {
        return $this->phones->normalize($phone);
    }

    public function findByPhone(string $phone): ?Customer
    {
        $normalized = $this->phones->normalize($phone);

        return $normalized === '' ? null : Customer::query()->where('phone_normalized', $normalized)->first();
    }

    /** @param array<string, mixed> $customer */
    public function recordOrder(Order $order, array $customer): Customer
    {
        $normalized = $this->phones->normalize((string) $customer['phone']);
        if ($normalized === '') {
            return $this->createFallback($order, $customer);
        }

        $profile = Customer::query()->where('phone_normalized', $normalized)->lockForUpdate()->first();
        if ($profile === null) {
            try {
                $profile = Customer::query()->create(['phone' => (string) $customer['phone'], 'phone_normalized' => $normalized, 'orders_count' => 0]);
            } catch (QueryException $exception) {
                if (! str_contains($exception->getMessage(), 'phone_normalized')) {
                    throw $exception;
                }
                $profile = Customer::query()->where('phone_normalized', $normalized)->lockForUpdate()->firstOrFail();
            }
        }

        $previous = $profile->last_order_at;
        $order->forceFill(['customer_id' => $profile->id, 'customer_previous_order_at' => $previous])->saveQuietly();
        $profile->forceFill([
            'phone' => (string) $customer['phone'],
            'name' => $customer['full_name'] ?? null,
            'governorate' => $customer['governorate'] ?? null,
            'city' => $customer['city'] ?? null,
            'address' => $customer['address'] ?? null,
            'last_order_at' => $order->created_at ?? now(),
            'orders_count' => ((int) $profile->orders_count) + 1,
        ])->save();

        return $profile;
    }

    /** @param array<string, mixed> $customer */
    private function createFallback(Order $order, array $customer): Customer
    {
        $profile = Customer::query()->create(['phone' => (string) ($customer['phone'] ?? ''), 'phone_normalized' => 'invalid-'.Str::lower(Str::random(24)), 'name' => $customer['full_name'] ?? null, 'governorate' => $customer['governorate'] ?? null, 'city' => $customer['city'] ?? null, 'address' => $customer['address'] ?? null, 'last_order_at' => $order->created_at ?? Carbon::now(), 'orders_count' => 1]);
        $order->forceFill(['customer_id' => $profile->id, 'customer_previous_order_at' => null])->saveQuietly();

        return $profile;
    }
}
