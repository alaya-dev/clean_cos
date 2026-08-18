<?php

namespace App\Domain\Orders\Actions;

use App\Domain\Catalog\Models\InventoryMovement;
use App\Domain\Commerce\Models\Order;
use App\Domain\Orders\Models\InventoryRestorationMarker;

class RestoreOrderStockOnceAction
{
    public function handle(Order $order, int $actorId, string $reason): void
    {
        $marker = InventoryRestorationMarker::query()->where('order_id', $order->id)->where('restoration_reason', $reason)->lockForUpdate()->first();
        if ($marker) {
            return;
        }

        $firstMovementId = null;
        foreach ($order->items()->with(['product', 'variant'])->get() as $item) {
            $target = $item->variant ?? $item->product;
            if (! $target) {
                continue;
            }
            $before = (int) $target->stock_quantity;
            $target->increment('stock_quantity', $item->quantity);
            $movement = InventoryMovement::query()->create(['product_id' => $item->product_id, 'product_variant_id' => $item->product_variant_id, 'actor_user_id' => $actorId, 'type' => 'order_restoration', 'quantity_delta' => $item->quantity, 'quantity_before' => $before, 'quantity_after' => $before + $item->quantity, 'reason' => 'Restauration commande '.$order->public_reference.' '.$reason]);
            $firstMovementId ??= $movement->id;
        }

        InventoryRestorationMarker::query()->create(['order_id' => $order->id, 'restoration_reason' => $reason, 'inventory_movement_id' => $firstMovementId, 'created_at' => now()]);
    }
}
