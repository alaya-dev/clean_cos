<?php

namespace App\Domain\Commerce\Services;

use App\Domain\Commerce\Models\Customer;
use App\Domain\Commerce\Models\Order;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class CustomerHistoryBackfillService
{
    public function __construct(private readonly CustomerPhoneNormalizer $phones) {}

    /**
     * @return array{orders_scanned:int,orders_linked:int,customers_created:int,customers_updated:int,orders_skipped_invalid_phone:int}
     */
    public function handle(bool $dryRun = false, int $chunkSize = 500): array
    {
        $stats = ['orders_scanned' => 0, 'orders_linked' => 0, 'customers_created' => 0, 'customers_updated' => 0, 'orders_skipped_invalid_phone' => 0];
        $lastOrderAtByPhone = [];
        $orderCountByPhone = [];
        $seenCustomerIds = [];
        $seenPhones = [];

        Order::query()->select(['id', 'customer_id', 'customer_phone', 'customer_name', 'customer_governorate', 'customer_city', 'customer_address', 'created_at', 'customer_previous_order_at'])
            ->orderBy('created_at')->orderBy('id')->chunk($chunkSize, function (Collection $orders) use (&$stats, &$lastOrderAtByPhone, &$orderCountByPhone, &$seenCustomerIds, &$seenPhones, $dryRun): void {
                $process = function () use ($orders, &$stats, &$lastOrderAtByPhone, &$orderCountByPhone, &$seenCustomerIds, &$seenPhones, $dryRun): void {
                    foreach ($orders as $order) {
                        $stats['orders_scanned']++;
                        $normalized = $this->phones->normalize((string) $order->customer_phone);
                        if ($normalized === '') {
                            $stats['orders_skipped_invalid_phone']++;

                            continue;
                        }

                        $previous = $lastOrderAtByPhone[$normalized] ?? null;
                        $orderCountByPhone[$normalized] = ($orderCountByPhone[$normalized] ?? 0) + 1;
                        $lastOrderAtByPhone[$normalized] = $order->created_at;
                        if ($dryRun) {
                            $stats['orders_linked']++;
                            if (! isset($seenPhones[$normalized])) {
                                $seenPhones[$normalized] = true;
                                Customer::query()->where('phone_normalized', $normalized)->exists()
                                    ? $stats['customers_updated']++
                                    : $stats['customers_created']++;
                            }

                            continue;
                        }

                        [$profile, $created] = $this->findOrCreateProfile($order, $normalized);
                        $profile->forceFill([
                            'phone' => $order->customer_phone,
                            'name' => $order->customer_name,
                            'governorate' => $order->customer_governorate,
                            'city' => $order->customer_city,
                            'address' => $order->customer_address,
                            'last_order_at' => $order->created_at,
                            'orders_count' => $orderCountByPhone[$normalized],
                        ]);
                        if ($profile->isDirty()) {
                            $profile->save();
                        }

                        if ($created) {
                            $stats['customers_created']++;
                        } elseif (! isset($seenCustomerIds[$profile->id])) {
                            $stats['customers_updated']++;
                        }
                        $seenCustomerIds[$profile->id] = true;
                        $order->forceFill(['customer_id' => $profile->id, 'customer_previous_order_at' => $previous]);
                        if ($order->isDirty()) {
                            $order->saveQuietly();
                        }
                        $stats['orders_linked']++;
                    }
                };

                if ($dryRun) {
                    $process();
                } else {
                    DB::transaction($process, 3);
                }
            });

        return $stats;
    }

    /** @return array{0:Customer,1:bool} */
    private function findOrCreateProfile(Order $order, string $normalized): array
    {
        $profile = Customer::query()->where('phone_normalized', $normalized)->lockForUpdate()->first();
        if ($profile !== null) {
            return [$profile, false];
        }

        try {
            return [Customer::query()->create(['phone' => $order->customer_phone, 'phone_normalized' => $normalized, 'orders_count' => 0]), true];
        } catch (QueryException $exception) {
            if (! str_contains($exception->getMessage(), 'phone_normalized')) {
                throw $exception;
            }

            return [Customer::query()->where('phone_normalized', $normalized)->lockForUpdate()->firstOrFail(), false];
        }
    }
}
