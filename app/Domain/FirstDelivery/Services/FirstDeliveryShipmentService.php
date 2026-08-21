<?php

namespace App\Domain\FirstDelivery\Services;

use App\Domain\Commerce\Models\Order;
use App\Domain\FirstDelivery\Enums\FirstDeliveryStatus;
use App\Domain\FirstDelivery\Models\FirstDeliveryShipment;
use App\Domain\Navex\Enums\NavexDeliveryStatus;
use App\Jobs\CreateFirstDeliveryShipmentJob;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FirstDeliveryShipmentService
{
    public function __construct(
        private readonly FirstDeliveryConfigurationService $configurations,
        private readonly FirstDeliveryLocalityService $localities,
        private readonly FirstDeliveryShipmentPayloadFactory $payloads,
    ) {}

    public function queue(Order $order, string $creationMode): FirstDeliveryShipment
    {
        return DB::transaction(function () use ($order, $creationMode): FirstDeliveryShipment {
            $order = Order::query()
                ->with(['items', 'checkoutValues', 'navexShipment', 'firstDeliveryShipment'])
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($order->status !== 'confirmee') {
                throw ValidationException::withMessages(['order' => 'Seules les commandes confirmées peuvent être envoyées à First Delivery.']);
            }
            if ($order->navexShipment !== null && $order->navexShipment->status !== NavexDeliveryStatus::Cancelled) {
                throw ValidationException::withMessages(['provider' => 'Cette commande possède déjà une expédition Navex.']);
            }
            if ($order->total_millimes > 999_000) {
                throw ValidationException::withMessages(['total' => 'First Delivery accepte un montant à encaisser compris entre 0 et 999 DT.']);
            }

            $configuration = $this->configurations->usable();
            if ($configuration === null) {
                throw ValidationException::withMessages(['configuration' => 'La configuration First Delivery est incomplète ou désactivée.']);
            }
            if ($creationMode === 'automatic' && $configuration->mode !== 'automatic') {
                throw ValidationException::withMessages(['mode' => 'L’envoi automatique First Delivery n’est pas activé.']);
            }
            if ($creationMode === 'manual' && ! in_array($configuration->mode, ['manual', 'automatic'], true)) {
                throw ValidationException::withMessages(['mode' => 'L’envoi manuel First Delivery n’est pas disponible.']);
            }

            $locality = $this->localities->resolveForOrder($order);
            if ($locality === null) {
                throw ValidationException::withMessages(['locality_id' => 'Sélectionnez une localité First Delivery correspondant à cette adresse.']);
            }
            if ($order->first_delivery_locality_id === null) {
                $order->update(['first_delivery_locality_id' => $locality->locality_id]);
            }

            $shipment = $order->firstDeliveryShipment;
            if ($shipment !== null) {
                if ($shipment->local_status !== FirstDeliveryStatus::SynchronizationError || filled($shipment->barcode)) {
                    throw ValidationException::withMessages(['shipment' => 'Une expédition First Delivery existe déjà pour cette commande.']);
                }
                $payload = $this->payloads->make($order, $locality);
                $shipment->update([
                    'first_delivery_configuration_id' => $configuration->id,
                    'locality_id' => $locality->locality_id,
                    'local_status' => FirstDeliveryStatus::PendingSend,
                    'creation_mode' => $creationMode,
                    'next_retry_at' => null,
                    'last_error' => null,
                    'request_snapshot_encrypted' => Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR)),
                ]);
            } else {
                $payload = $this->payloads->make($order, $locality);
                $shipment = FirstDeliveryShipment::query()->create([
                    'order_id' => $order->id,
                    'first_delivery_configuration_id' => $configuration->id,
                    'locality_id' => $locality->locality_id,
                    'local_status' => FirstDeliveryStatus::PendingSend,
                    'creation_mode' => $creationMode,
                    'request_snapshot_encrypted' => Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR)),
                ]);
                $shipment->statusHistory()->create([
                    'local_status' => FirstDeliveryStatus::PendingSend,
                    'recorded_at' => now(),
                ]);
            }

            DB::afterCommit(fn () => CreateFirstDeliveryShipmentJob::dispatch($shipment->public_id)->onQueue('integrations'));

            return $shipment;
        });
    }

    public function retry(Order $order): FirstDeliveryShipment
    {
        $configuration = $this->configurations->current();
        if ($configuration === null || $configuration->mode === 'disabled') {
            throw ValidationException::withMessages(['configuration' => 'La configuration First Delivery ne permet pas de relancer cet envoi.']);
        }

        return $this->queue($order, $configuration->mode === 'automatic' ? 'automatic' : 'manual');
    }

    /** @return array{ready: bool, reasons: array<int, string>, mode: string} */
    public function ready(Order $order): array
    {
        $configuration = $this->configurations->current();
        $mode = $configuration === null ? 'disabled' : $configuration->mode;
        $reasons = [];

        if ($order->status !== 'confirmee') {
            $reasons[] = 'La commande doit être confirmée.';
        }
        if ($order->navexShipment()->where('status', '!=', NavexDeliveryStatus::Cancelled->value)->exists()) {
            $reasons[] = 'Une expédition Navex existe déjà.';
        }
        if ($configuration === null || ! $configuration->complete()) {
            $reasons[] = 'La configuration First Delivery est incomplète.';
        }
        if ($mode === 'disabled') {
            $reasons[] = 'L’intégration First Delivery est désactivée.';
        }
        if ($order->total_millimes > 999_000) {
            $reasons[] = 'Le total doit être compris entre 0 et 999 DT.';
        }
        if ($this->localities->resolveForOrder($order) === null) {
            $reasons[] = 'La localité First Delivery doit être sélectionnée.';
        }

        return ['ready' => $reasons === [], 'reasons' => $reasons, 'mode' => $mode];
    }
}
