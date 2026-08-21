<?php

namespace App\Domain\Commerce\Actions;

use App\Domain\Audit\Actions\RecordAuditEventAction;
use App\Domain\Catalog\Models\InventoryMovement;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Support\OrderStatusFlow;
use App\Domain\FirstDelivery\Enums\FirstDeliveryStatus;
use App\Domain\FirstDelivery\Services\FirstDeliveryShipmentService;
use App\Domain\Navex\Enums\NavexDeliveryStatus;
use App\Domain\Navex\Services\NavexShipmentService;
use App\Domain\Orders\Actions\RestoreOrderStockOnceAction;
use App\Domain\Orders\Models\InventoryRestorationMarker;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TransitionOrderStatusAction
{
    public function __construct(
        private readonly RestoreOrderStockOnceAction $restoreStock,
        private readonly RecordAuditEventAction $audit,
        private readonly NavexShipmentService $navexShipments,
        private readonly FirstDeliveryShipmentService $firstDeliveryShipments,
    ) {}

    public function handle(Order $order, string $toStatus, ?string $reason, int $actorId): Order
    {
        return DB::transaction(function () use ($order, $toStatus, $reason, $actorId): Order {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (! OrderStatusFlow::canTransition($order->status, $toStatus)) {
                throw ValidationException::withMessages(['status' => 'Transition de commande non autorisée.']);
            }

            if ($toStatus === 'annulee' && $order->navexShipment()->where('status', '!=', NavexDeliveryStatus::Cancelled->value)->exists()) {
                throw ValidationException::withMessages(['status' => 'Annulez d’abord le colis Navex avant d’annuler cette commande.']);
            }
            if ($toStatus === 'annulee' && $order->firstDeliveryShipment()->where('local_status', '!=', FirstDeliveryStatus::Cancelled->value)->exists()) {
                throw ValidationException::withMessages(['status' => 'Annulez d’abord l’expédition First Delivery avant d’annuler cette commande.']);
            }

            $fromStatus = $order->status;
            if ($fromStatus === 'annulee' && $toStatus !== 'annulee') {
                $this->reserveStockForReactivatedOrder($order, $actorId);
            }
            $reason ??= $toStatus === 'annulee' ? 'Annulation par l’opérateur.' : null;
            $order->update(['status' => $toStatus, 'lock_version' => $order->lock_version + 1]);
            DB::table('order_status_history')->insert(['order_id' => $order->id, 'from_status' => $fromStatus, 'to_status' => $toStatus, 'reason' => $reason, 'changed_by' => $actorId, 'created_at' => now()]);
            if (OrderStatusFlow::restoresStock($toStatus)) {
                $this->restoreStock->handle($order, $actorId, $toStatus);
            }

            $updated = $order->fresh() ?? $order;
            $this->audit->handle(
                'order.status_changed',
                $updated,
                User::query()->find($actorId),
                before: ['status' => $fromStatus],
                after: ['status' => $toStatus, 'from_status' => $fromStatus, 'to_status' => $toStatus, 'reason' => $reason],
            );
            if ($toStatus === 'confirmee') {
                try {
                    $this->navexShipments->queue($updated, 'automatic');
                } catch (ValidationException) {
                    // Delivery configuration or an existing shipment must never roll back the status change.
                }
                try {
                    $this->firstDeliveryShipments->queue($updated, 'automatic');
                } catch (ValidationException) {
                    // Delivery configuration or an existing shipment must never roll back the status change.
                }
            }

            return $updated;
        });
    }

    private function reserveStockForReactivatedOrder(Order $order, int $actorId): void
    {
        $items = $order->items()->get();
        $products = Product::withTrashed()
            ->whereIn('id', $items->pluck('product_id')->filter()->unique()->sort()->values())
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
        $variants = ProductVariant::query()
            ->whereIn('id', $items->pluck('product_variant_id')->filter()->unique()->sort()->values())
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        foreach ($items as $item) {
            $product = $products->get($item->product_id);
            $target = $item->product_variant_id ? $variants->get($item->product_variant_id) : $product;
            if ($product === null || $product->trashed() || ! $product->is_active || $target === null || ! $target->is_active || (int) $target->stock_quantity < $item->quantity) {
                throw ValidationException::withMessages(['status' => 'Impossible de réactiver cette commande : un article est indisponible ou son stock est insuffisant.']);
            }
        }

        foreach ($items as $item) {
            $target = $item->product_variant_id ? $variants->get($item->product_variant_id) : $products->get($item->product_id);
            if ($target === null) {
                continue;
            }

            $before = (int) $target->stock_quantity;
            $target->decrement('stock_quantity', $item->quantity);
            InventoryMovement::query()->create([
                'product_id' => $item->product_id,
                'product_variant_id' => $item->product_variant_id,
                'actor_user_id' => $actorId,
                'type' => 'order_reactivation_deduction',
                'quantity_delta' => -$item->quantity,
                'quantity_before' => $before,
                'quantity_after' => $before - $item->quantity,
                'reason' => 'Réactivation commande '.$order->public_reference,
            ]);
        }

        InventoryRestorationMarker::query()
            ->where('order_id', $order->id)
            ->whereIn('restoration_reason', ['annulee', 'cancelled'])
            ->delete();
    }
}
