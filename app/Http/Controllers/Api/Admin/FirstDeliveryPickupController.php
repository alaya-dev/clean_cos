<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Audit\Actions\RecordAuditEventAction;
use App\Domain\FirstDelivery\Enums\FirstDeliveryPickupStatus;
use App\Domain\FirstDelivery\Models\FirstDeliveryPickup;
use App\Domain\FirstDelivery\Models\FirstDeliveryPickupItem;
use App\Domain\FirstDelivery\Services\FirstDeliveryPickupService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Admin\CreateFirstDeliveryPickupRequest;
use App\Jobs\RefreshFirstDeliveryPickupPrintJob;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FirstDeliveryPickupController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'in:'.implode(',', array_column(FirstDeliveryPickupStatus::cases(), 'value'))],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);
        $pickups = FirstDeliveryPickup::query()
            ->with(['items:id,first_delivery_pickup_id,barcode,order_reference', 'requestedBy:id,public_id,name'])
            ->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->latest()
            ->paginate($data['per_page'] ?? 20);
        $pickups->getCollection()->transform(fn (FirstDeliveryPickup $pickup): array => $this->safe($pickup));

        return response()->json(['data' => $pickups]);
    }

    public function store(
        CreateFirstDeliveryPickupRequest $request,
        FirstDeliveryPickupService $pickups,
        RecordAuditEventAction $audit,
    ): JsonResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $pickup = $pickups->queue($request->validated('shipment_public_ids'), $actor);
        $audit->handle(
            'delivery.first_delivery.pickup_queued',
            $pickup,
            $actor,
            after: ['shipment_count' => $pickup->shipment_count],
        );

        return response()->json(['data' => [
            'pickup' => $this->safe($pickup),
            'notice' => 'Le manifeste First Delivery a été placé dans la file de création.',
        ]], 202);
    }

    public function retry(
        Request $request,
        FirstDeliveryPickup $pickup,
        FirstDeliveryPickupService $pickups,
        RecordAuditEventAction $audit,
    ): JsonResponse {
        $request->validate(['confirm_retry' => ['required', 'accepted']]);
        $pickup = $pickups->retry($pickup);
        $audit->handle('delivery.first_delivery.pickup_retry_queued', $pickup, $request->user());

        return response()->json(['data' => [
            'pickup' => $this->safe($pickup),
            'notice' => 'Le manifeste a été remis dans la file de création.',
        ]]);
    }

    public function refreshPrint(
        Request $request,
        FirstDeliveryPickup $pickup,
        RecordAuditEventAction $audit,
    ): JsonResponse {
        $request->validate(['confirm_refresh' => ['required', 'accepted']]);
        $pickup = DB::transaction(function () use ($pickup): FirstDeliveryPickup {
            $locked = FirstDeliveryPickup::query()->whereKey($pickup->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== FirstDeliveryPickupStatus::Created || blank($locked->provider_pickup_id)) {
                throw ValidationException::withMessages(['pickup' => 'Le manifeste doit être créé avant de régénérer son lien.']);
            }
            $locked->update(['print_refresh_pending' => true, 'print_error' => null]);
            DB::afterCommit(fn () => RefreshFirstDeliveryPickupPrintJob::dispatch($locked->public_id)->onQueue('integrations'));

            return $locked;
        });
        $audit->handle('delivery.first_delivery.pickup_print_requested', $pickup, $request->user());

        return response()->json(['data' => [
            'pickup' => $this->safe($pickup),
            'notice' => 'Le lien d’impression du manifeste est en cours de préparation.',
        ]]);
    }

    /** @return array<string, mixed> */
    private function safe(FirstDeliveryPickup $pickup): array
    {
        $pickup->loadMissing(['items:id,first_delivery_pickup_id,barcode,order_reference', 'requestedBy:id,public_id,name']);

        return [
            'public_id' => $pickup->public_id,
            'provider_pickup_id' => $pickup->provider_pickup_id,
            'status' => $pickup->status->value,
            'status_label' => $pickup->status->label(),
            'print_url' => $pickup->print_url,
            'shipment_count' => $pickup->shipment_count,
            'retryable' => $pickup->retryable,
            'print_refresh_pending' => $pickup->print_refresh_pending,
            'last_error' => $pickup->last_error,
            'print_error' => $pickup->print_error,
            'safe_message' => $pickup->safe_message,
            'queued_at' => $pickup->queued_at->toIso8601String(),
            'confirmed_at' => $pickup->confirmed_at?->toIso8601String(),
            'last_printed_at' => $pickup->last_printed_at?->toIso8601String(),
            'created_at' => $pickup->created_at?->toIso8601String(),
            'requested_by' => $pickup->requestedBy?->only(['public_id', 'name']),
            'items' => $pickup->items->map(fn (FirstDeliveryPickupItem $item): array => [
                'barcode' => $item->barcode,
                'order_reference' => $item->order_reference,
            ])->values()->all(),
        ];
    }
}
