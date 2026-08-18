<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Audit\Actions\RecordAuditEventAction;
use App\Domain\Commerce\Models\Order;
use App\Domain\Navex\Enums\NavexDeliveryStatus;
use App\Domain\Navex\Models\NavexShipment;
use App\Domain\Navex\Services\NavexShipmentService;
use App\Http\Controllers\Controller;
use App\Jobs\DeleteNavexShipmentJob;
use App\Jobs\ReconcileNavexShipmentJob;
use App\Jobs\SynchronizeNavexShipmentJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class NavexShipmentController extends Controller
{
    public function send(Request $request, Order $order, NavexShipmentService $shipments, RecordAuditEventAction $audit): JsonResponse
    {
        $request->validate(['confirm_send' => ['required', 'accepted']]);
        $shipment = $shipments->queue($order, 'manual');
        $audit->handle('navex.shipment_queued', $shipment, $request->user(), after: ['order_reference' => $order->public_reference, 'mode' => 'manual']);

        return response()->json(['data' => ['shipment' => $this->safe($shipment), 'notice' => 'Le colis a été placé dans la file d’envoi Navex.']]);
    }

    public function synchronize(Order $order, RecordAuditEventAction $audit, Request $request): JsonResponse
    {
        $shipment = $this->shipment($order);
        if (blank($shipment->tracking_code)) {
            if ($shipment->status === NavexDeliveryStatus::Accepted) {
                ReconcileNavexShipmentJob::dispatch($shipment->public_id)->onQueue('integrations');
                $audit->handle('navex.shipment_barcode_sync_requested', $shipment, $request->user(), after: ['order_reference' => $order->public_reference]);

                return response()->json(['data' => ['shipment' => $this->safe($shipment), 'notice' => 'Recherche du code de suivi Navex en cours.']]);
            }
            throw ValidationException::withMessages(['shipment' => 'Aucun code de suivi Navex n’est encore disponible.']);
        }
        SynchronizeNavexShipmentJob::dispatch($shipment->public_id)->onQueue('integrations');
        $audit->handle('navex.shipment_sync_requested', $shipment, $request->user(), after: ['order_reference' => $order->public_reference]);

        return response()->json(['data' => ['shipment' => $this->safe($shipment), 'notice' => 'Synchronisation Navex en cours.']]);
    }

    public function reconcile(Order $order, RecordAuditEventAction $audit, Request $request): JsonResponse
    {
        $shipment = $this->shipment($order);
        if ($shipment->status !== NavexDeliveryStatus::UncertainResult) {
            throw ValidationException::withMessages(['shipment' => 'La réconciliation est disponible uniquement pour un résultat incertain.']);
        }
        ReconcileNavexShipmentJob::dispatch($shipment->public_id)->onQueue('integrations');
        $audit->handle('navex.shipment_reconciliation_requested', $shipment, $request->user(), after: ['order_reference' => $order->public_reference]);

        return response()->json(['data' => ['shipment' => $this->safe($shipment), 'notice' => 'Vérification Navex en cours avant toute nouvelle tentative.']]);
    }

    public function retry(Request $request, Order $order, NavexShipmentService $shipments, RecordAuditEventAction $audit): JsonResponse
    {
        $request->validate(['confirm_retry' => ['required', 'accepted']]);
        $shipment = $this->shipment($order);
        if ($shipment->status !== NavexDeliveryStatus::SynchronizationError) {
            throw ValidationException::withMessages(['shipment' => 'Cette expédition ne peut pas être relancée dans son état actuel.']);
        }
        $shipment = $shipments->retry($order);
        $audit->handle('navex.shipment_retry_queued', $shipment, $request->user(), after: ['order_reference' => $order->public_reference]);

        return response()->json(['data' => ['shipment' => $this->safe($shipment), 'notice' => 'Le même dossier Navex a été remis dans la file d’envoi.']]);
    }

    public function cancel(Request $request, Order $order, RecordAuditEventAction $audit): JsonResponse
    {
        $request->validate(['confirm_cancellation' => ['required', 'accepted']]);
        $shipment = $this->shipment($order);
        if (! $shipment->canStillBeModifiedAtNavex()) {
            throw ValidationException::withMessages(['shipment' => 'L’annulation est disponible uniquement lorsque Navex indique « En attente ».']);
        }
        $shipment->update(['status' => NavexDeliveryStatus::CancellationPending, 'cancel_requested_at' => now()]);
        $shipment->statusHistory()->create(['status' => NavexDeliveryStatus::CancellationPending, 'recorded_at' => now()]);
        DeleteNavexShipmentJob::dispatch($shipment->public_id)->onQueue('integrations');
        $audit->handle('navex.shipment_cancellation_requested', $shipment, $request->user(), after: ['order_reference' => $order->public_reference]);

        return response()->json(['data' => ['shipment' => $this->safe($shipment->fresh() ?? $shipment), 'notice' => 'Demande d’annulation Navex en cours.']]);
    }

    private function shipment(Order $order): NavexShipment
    {
        return NavexShipment::query()->where('order_id', $order->id)->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function safe(NavexShipment $shipment): array
    {
        return [
            'public_id' => $shipment->public_id,
            'status' => $shipment->status->value,
            'status_label' => $shipment->status->label(),
            'tracking_code' => $shipment->tracking_code,
            'raw_status' => $shipment->raw_status,
            'raw_reason' => $shipment->raw_reason,
            'sent_at' => $shipment->sent_at?->toIso8601String(),
            'last_synchronized_at' => $shipment->last_synchronized_at?->toIso8601String(),
            'creation_mode' => $shipment->creation_mode,
        ];
    }
}
