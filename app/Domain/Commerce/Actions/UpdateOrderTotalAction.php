<?php

namespace App\Domain\Commerce\Actions;

use App\Domain\Commerce\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateOrderTotalAction
{
    public function handle(Order $order, int $lockVersion, ?int $manualTotalMillimes): Order
    {
        return DB::transaction(function () use ($order, $lockVersion, $manualTotalMillimes): Order {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($order->lock_version !== $lockVersion) {
                throw ValidationException::withMessages(['lock_version' => 'La commande a été modifiée. Actualisez-la avant de continuer.']);
            }

            $calculatedTotal = max(
                0,
                (int) $order->subtotal_millimes
                    - (int) $order->promo_code_discount_millimes
                    + (int) $order->shipping_fee_millimes,
            );
            $total = $manualTotalMillimes ?? $calculatedTotal;

            $order->update([
                'manual_total_millimes' => $manualTotalMillimes,
                'total_millimes' => $total,
                'lock_version' => $order->lock_version + 1,
            ]);

            return $order->fresh() ?? $order;
        });
    }
}
