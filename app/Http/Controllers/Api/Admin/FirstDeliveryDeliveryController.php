<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\FirstDelivery\Enums\FirstDeliveryStatus;
use App\Domain\FirstDelivery\Models\FirstDeliveryShipment;
use App\Domain\FirstDelivery\Services\FirstDeliveryPickupService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FirstDeliveryDeliveryController extends Controller
{
    public function index(Request $request, FirstDeliveryPickupService $pickups): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'in:'.implode(',', array_map(
                fn (FirstDeliveryStatus $status): string => $status->value,
                FirstDeliveryStatus::cases(),
            ))],
            'action_required' => ['nullable', 'boolean'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $shipments = FirstDeliveryShipment::query()
            ->with(['order:id,public_reference,customer_name,status', 'pickupItem.pickup'])
            ->when($data['status'] ?? null, fn ($query, $status) => $query->where('local_status', $status))
            ->when(($data['action_required'] ?? false) === true, fn ($query) => $query->whereIn('local_status', [
                FirstDeliveryStatus::ManualActionRequired->value,
                FirstDeliveryStatus::UncertainResult->value,
                FirstDeliveryStatus::SynchronizationError->value,
            ]))
            ->when($data['date_from'] ?? null, fn ($query, $date) => $query->whereDate('created_at', '>=', $date))
            ->when($data['date_to'] ?? null, fn ($query, $date) => $query->whereDate('created_at', '<=', $date))
            ->latest('updated_at')
            ->paginate($data['per_page'] ?? 25);

        $shipments->getCollection()->each(function (FirstDeliveryShipment $shipment) use ($pickups): void {
            $reason = $pickups->ineligibilityReason($shipment);
            $shipment->setAttribute('pickup_eligible', $reason === null);
            $shipment->setAttribute('pickup_eligibility_reason', $reason);
            $shipment->setAttribute('pickup', $shipment->pickupItem?->pickup === null ? null : [
                'public_id' => $shipment->pickupItem->pickup->public_id,
                'provider_pickup_id' => $shipment->pickupItem->pickup->provider_pickup_id,
                'status' => $shipment->pickupItem->pickup->status->value,
                'status_label' => $shipment->pickupItem->pickup->status->label(),
            ]);
            $shipment->unsetRelation('pickupItem');
        });

        $summary = [
            'pending_send' => FirstDeliveryShipment::query()->where('local_status', FirstDeliveryStatus::PendingSend)->count(),
            'in_delivery' => FirstDeliveryShipment::query()->where('local_status', FirstDeliveryStatus::InProgress)->count(),
            'delivered_today' => FirstDeliveryShipment::query()
                ->where('local_status', FirstDeliveryStatus::Delivered)
                ->whereDate('last_synced_at', now()->toDateString())
                ->count(),
            'returned' => FirstDeliveryShipment::query()
                ->whereIn('local_status', [
                    FirstDeliveryStatus::ReturnedToSender,
                    FirstDeliveryStatus::FinalReturn,
                ])
                ->count(),
            'action_required' => FirstDeliveryShipment::query()
                ->whereIn('local_status', [
                    FirstDeliveryStatus::ManualActionRequired,
                    FirstDeliveryStatus::UncertainResult,
                    FirstDeliveryStatus::SynchronizationError,
                ])
                ->count(),
        ];

        return response()->json(['data' => $shipments, 'meta' => ['summary' => $summary]]);
    }
}
