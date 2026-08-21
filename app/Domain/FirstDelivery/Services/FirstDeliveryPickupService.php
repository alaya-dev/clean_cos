<?php

namespace App\Domain\FirstDelivery\Services;

use App\Domain\FirstDelivery\Enums\FirstDeliveryPickupStatus;
use App\Domain\FirstDelivery\Enums\FirstDeliveryStatus;
use App\Domain\FirstDelivery\Models\FirstDeliveryPickup;
use App\Domain\FirstDelivery\Models\FirstDeliveryShipment;
use App\Jobs\CreateFirstDeliveryPickupJob;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FirstDeliveryPickupService
{
    public function __construct(private readonly FirstDeliveryConfigurationService $configurations) {}

    /** @param array<int, string> $shipmentPublicIds */
    public function queue(array $shipmentPublicIds, User $actor): FirstDeliveryPickup
    {
        return DB::transaction(function () use ($shipmentPublicIds, $actor): FirstDeliveryPickup {
            $configuration = $this->configurations->usable();
            if ($configuration === null) {
                throw ValidationException::withMessages(['configuration' => 'La configuration First Delivery doit être active et complète.']);
            }

            $publicIds = array_values(array_unique($shipmentPublicIds));
            $shipments = FirstDeliveryShipment::query()
                ->with(['order:id,public_reference', 'pickupItem'])
                ->whereIn('public_id', $publicIds)
                ->lockForUpdate()
                ->get();
            if ($shipments->count() !== count($publicIds)) {
                throw ValidationException::withMessages(['shipment_public_ids' => 'Un colis sélectionné est introuvable.']);
            }

            foreach ($shipments as $shipment) {
                $reason = $this->ineligibilityReason($shipment);
                if ($reason !== null) {
                    throw ValidationException::withMessages(['shipment_public_ids' => $reason]);
                }
                if ($shipment->order === null) {
                    throw ValidationException::withMessages(['shipment_public_ids' => 'La commande liée au colis est introuvable.']);
                }
            }

            $pickup = FirstDeliveryPickup::query()->create([
                'first_delivery_configuration_id' => $configuration->id,
                'status' => FirstDeliveryPickupStatus::Pending,
                'shipment_count' => $shipments->count(),
                'requested_by' => $actor->id,
                'queued_at' => now(),
            ]);

            foreach ($shipments as $shipment) {
                $order = $shipment->order;
                if ($order === null) {
                    throw ValidationException::withMessages(['shipment_public_ids' => 'La commande liée au colis est introuvable.']);
                }
                $pickup->items()->create([
                    'first_delivery_shipment_id' => $shipment->id,
                    'barcode' => $shipment->barcode,
                    'order_reference' => $order->public_reference,
                    'created_at' => now(),
                ]);
            }

            DB::afterCommit(fn () => CreateFirstDeliveryPickupJob::dispatch($pickup->public_id)->onQueue('integrations'));

            return $pickup->load('items');
        });
    }

    public function retry(FirstDeliveryPickup $pickup): FirstDeliveryPickup
    {
        return DB::transaction(function () use ($pickup): FirstDeliveryPickup {
            $locked = FirstDeliveryPickup::query()->whereKey($pickup->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== FirstDeliveryPickupStatus::Failed || ! $locked->retryable) {
                throw ValidationException::withMessages(['pickup' => 'Ce manifeste ne peut pas être relancé dans son état actuel.']);
            }
            $locked->load('items.shipment.pickupItem');
            foreach ($locked->items as $item) {
                $shipment = $item->shipment;
                if ($shipment === null || $this->ineligibilityReason($shipment, $locked->id) !== null) {
                    throw ValidationException::withMessages(['pickup' => 'Un colis du manifeste n’est plus éligible à une relance.']);
                }
            }
            $locked->update([
                'status' => FirstDeliveryPickupStatus::Pending,
                'retryable' => false,
                'last_error' => null,
                'safe_message' => null,
                'queued_at' => now(),
            ]);
            DB::afterCommit(fn () => CreateFirstDeliveryPickupJob::dispatch($locked->public_id)->onQueue('integrations'));

            return $locked->fresh(['items']) ?? $locked;
        });
    }

    public function ineligibilityReason(FirstDeliveryShipment $shipment, ?int $currentPickupId = null): ?string
    {
        if (! is_string($shipment->barcode) || preg_match('/^\d{12}$/D', $shipment->barcode) !== 1) {
            return 'Le colis ne possède pas un barcode First Delivery valide.';
        }
        if ($shipment->remote_state_code !== 0 || $shipment->local_status !== FirstDeliveryStatus::Pending) {
            return 'Seuls les colis confirmés « En attente » chez First Delivery peuvent être manifestés.';
        }
        if ($shipment->last_error !== null) {
            return 'Le colis possède une erreur First Delivery à résoudre avant le manifeste.';
        }
        if ($shipment->pickupItem !== null && $shipment->pickupItem->first_delivery_pickup_id !== $currentPickupId) {
            return 'Un colis sélectionné appartient déjà à un manifeste First Delivery.';
        }

        return null;
    }
}
