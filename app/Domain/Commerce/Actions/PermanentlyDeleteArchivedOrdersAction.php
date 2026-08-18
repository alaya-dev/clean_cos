<?php

namespace App\Domain\Commerce\Actions;

use App\Domain\Audit\Actions\RecordAuditEventAction;
use App\Domain\Commerce\Models\Order;
use App\Domain\MetaTracking\Models\MetaEvent;
use App\Domain\Navex\Enums\NavexDeliveryStatus;
use App\Domain\Navex\Models\NavexShipment;
use App\Domain\Orders\Actions\RestoreOrderStockOnceAction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PermanentlyDeleteArchivedOrdersAction
{
    public function __construct(
        private readonly RecordAuditEventAction $audit,
        private readonly RestoreOrderStockOnceAction $restoreStock,
    ) {}

    /**
     * @param  array<int, string>  $references
     * @return array{deleted: int}
     */
    public function handle(array $references, ?User $actor): array
    {
        return DB::transaction(function () use ($references, $actor): array {
            $orders = Order::query()
                ->whereIn('public_reference', $references)
                ->lockForUpdate()
                ->get();

            if ($orders->count() !== count($references)) {
                throw ValidationException::withMessages(['references' => 'Une commande sélectionnée est introuvable.']);
            }

            if ($orders->contains(fn (Order $order): bool => $order->archived_at === null)) {
                throw ValidationException::withMessages([
                    'archived' => 'Une commande doit d’abord être archivée avant sa suppression définitive.',
                ]);
            }

            $orderIds = $orders->modelKeys();
            $orders->loadCount('items');
            $shipments = NavexShipment::query()->whereIn('order_id', $orderIds)->lockForUpdate()->get();
            $hasInFlightMetaDelivery = MetaEvent::query()
                ->whereIn('order_id', $orderIds)
                ->whereIn('capi_state', ['pending', 'sending', 'temporary_failure'])
                ->lockForUpdate()
                ->exists();

            if ($hasInFlightMetaDelivery) {
                throw ValidationException::withMessages([
                    'references' => 'Une commande sélectionnée possède encore un événement Meta en attente. Réessayez après sa livraison.',
                ]);
            }

            $activeShipment = $shipments->first(fn (NavexShipment $shipment): bool => ! $this->shipmentCanBeRemoved($shipment));
            if ($activeShipment instanceof NavexShipment) {
                throw ValidationException::withMessages([
                    'references' => 'Cette commande est encore active chez Navex ('.$activeShipment->status->label().'). Annulez d’abord le colis dans Livraison Navex et attendez la confirmation avant de supprimer la commande.',
                ]);
            }

            $this->audit->handle(
                'order.bulk_permanently_deleted',
                $orders->firstOrFail(),
                $actor,
                after: ['count' => $orders->count(), 'references' => $orders->pluck('public_reference')->values()->all()],
            );
            foreach ($orders as $order) {
                $this->audit->handle(
                    'order.permanently_deleted',
                    $order,
                    $actor,
                    before: [
                        'public_reference' => $order->public_reference,
                        'status' => $order->status,
                        'total_millimes' => $order->total_millimes,
                        'item_count' => $order->items_count,
                    ],
                    after: ['deleted' => true],
                );
            }

            foreach ($orders as $order) {
                if (in_array($order->status, ['nouvelle', 'confirmee'], true) && $actor !== null) {
                    $this->restoreStock->handle($order, $actor->id, 'permanent_deletion');
                }
            }

            DB::table('inventory_restoration_markers')->whereIn('order_id', $orderIds)->delete();
            DB::table('checkout_idempotency_records')->whereIn('order_id', $orderIds)->delete();
            DB::table('order_notes')->whereIn('order_id', $orderIds)->delete();
            DB::table('order_status_history')->whereIn('order_id', $orderIds)->delete();
            DB::table('order_checkout_values')->whereIn('order_id', $orderIds)->delete();
            MetaEvent::query()->whereIn('order_id', $orderIds)->delete();
            NavexShipment::query()->whereIn('id', $shipments->modelKeys())->delete();
            DB::table('order_items')->whereIn('order_id', $orderIds)->delete();
            foreach ($orders as $order) {
                $order->delete();
            }

            return ['deleted' => count($orderIds)];
        });
    }

    private function shipmentCanBeRemoved(NavexShipment $shipment): bool
    {
        if ($shipment->status->terminal()) {
            return true;
        }

        if (filled($shipment->tracking_code)) {
            return false;
        }

        return in_array($shipment->status, [
            NavexDeliveryStatus::NotSent,
            NavexDeliveryStatus::SynchronizationError,
        ], true);
    }
}
