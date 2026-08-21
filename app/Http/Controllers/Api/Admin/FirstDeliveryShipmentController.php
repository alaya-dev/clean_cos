<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Audit\Actions\RecordAuditEventAction;
use App\Domain\Commerce\Models\Order;
use App\Domain\FirstDelivery\Enums\FirstDeliveryStatus;
use App\Domain\FirstDelivery\Models\FirstDeliveryShipment;
use App\Domain\FirstDelivery\Services\FirstDeliveryShipmentService;
use App\Http\Controllers\Controller;
use App\Jobs\CancelFirstDeliveryShipmentJob;
use App\Jobs\SynchronizeFirstDeliveryShipmentJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class FirstDeliveryShipmentController extends Controller
{
    public function send(
        Request $request,
        Order $order,
        FirstDeliveryShipmentService $shipments,
        RecordAuditEventAction $audit,
    ): JsonResponse {
        $request->validate(['confirm_send' => ['required', 'accepted']]);
        $shipment = $shipments->queue($order, 'manual');
        $audit->handle(
            'delivery.first_delivery.shipment_queued',
            $shipment,
            $request->user(),
            after: ['order_reference' => $order->public_reference, 'mode' => 'manual'],
        );

        return response()->json([
            'data' => [
                'shipment' => $this->safe($shipment),
                'notice' => 'Le colis a été placé dans la file d’envoi First Delivery.',
            ],
        ]);
    }

    public function synchronize(
        Order $order,
        Request $request,
        RecordAuditEventAction $audit,
    ): JsonResponse {
        $shipment = $this->shipment($order);
        if (blank($shipment->barcode)) {
            throw ValidationException::withMessages(['shipment' => 'Aucun barcode First Delivery n’est disponible.']);
        }
        SynchronizeFirstDeliveryShipmentJob::dispatch($shipment->public_id)->onQueue('integrations');
        $audit->handle(
            'delivery.first_delivery.shipment_sync_requested',
            $shipment,
            $request->user(),
            after: ['order_reference' => $order->public_reference],
        );

        return response()->json([
            'data' => [
                'shipment' => $this->safe($shipment),
                'notice' => 'Synchronisation First Delivery demandée.',
            ],
        ]);
    }

    public function retry(
        Request $request,
        Order $order,
        FirstDeliveryShipmentService $shipments,
        RecordAuditEventAction $audit,
    ): JsonResponse {
        $request->validate(['confirm_retry' => ['required', 'accepted']]);
        $shipment = $this->shipment($order);
        if ($shipment->local_status !== FirstDeliveryStatus::SynchronizationError || filled($shipment->barcode)) {
            throw ValidationException::withMessages(['shipment' => 'Cette expédition ne peut pas être relancée dans son état actuel.']);
        }
        $shipment = $shipments->retry($order);
        $audit->handle(
            'delivery.first_delivery.shipment_retry_queued',
            $shipment,
            $request->user(),
            after: ['order_reference' => $order->public_reference],
        );

        return response()->json([
            'data' => [
                'shipment' => $this->safe($shipment),
                'notice' => 'Le dossier First Delivery a été remis dans la file d’envoi.',
            ],
        ]);
    }

    public function cancel(
        Request $request,
        Order $order,
        RecordAuditEventAction $audit,
    ): JsonResponse {
        $request->validate(['confirm_cancellation' => ['required', 'accepted']]);
        $shipment = $this->shipment($order);
        if (! $shipment->canBeCancelled()) {
            throw ValidationException::withMessages([
                'shipment' => 'L’annulation est disponible uniquement lorsque First Delivery indique « En attente ».',
            ]);
        }

        $shipment->update([
            'local_status' => FirstDeliveryStatus::CancellationPending,
            'cancel_requested_at' => now(),
        ]);
        $shipment->statusHistory()->create([
            'local_status' => FirstDeliveryStatus::CancellationPending,
            'remote_state_code' => $shipment->remote_state_code,
            'remote_state' => $shipment->remote_state,
            'recorded_at' => now(),
        ]);
        CancelFirstDeliveryShipmentJob::dispatch($shipment->public_id)->onQueue('integrations');
        $audit->handle(
            'delivery.first_delivery.shipment_cancellation_requested',
            $shipment,
            $request->user(),
            after: ['order_reference' => $order->public_reference],
        );

        return response()->json([
            'data' => [
                'shipment' => $this->safe($shipment->fresh() ?? $shipment),
                'notice' => 'Demande d’annulation First Delivery envoyée.',
            ],
        ]);
    }

    private function shipment(Order $order): FirstDeliveryShipment
    {
        return FirstDeliveryShipment::query()->where('order_id', $order->id)->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function safe(FirstDeliveryShipment $shipment): array
    {
        return [
            'public_id' => $shipment->public_id,
            'provider' => 'first_delivery',
            'provider_label' => 'First Delivery',
            'status' => $shipment->local_status->value,
            'status_label' => $shipment->local_status->label(),
            'barcode' => $shipment->barcode,
            'remote_state_code' => $shipment->remote_state_code,
            'remote_state' => $shipment->remote_state,
            'print_url' => $shipment->print_url,
            'sent_at' => $shipment->sent_at?->toIso8601String(),
            'last_synced_at' => $shipment->last_synced_at?->toIso8601String(),
            'last_error' => $shipment->last_error,
            'creation_mode' => $shipment->creation_mode,
        ];
    }
}
