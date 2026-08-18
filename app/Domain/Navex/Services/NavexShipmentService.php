<?php

namespace App\Domain\Navex\Services;

use App\Domain\Commerce\Models\Order;
use App\Domain\Navex\Enums\NavexDeliveryStatus;
use App\Domain\Navex\Models\NavexShipment;
use App\Jobs\CreateNavexShipmentJob;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class NavexShipmentService
{
    public function __construct(
        private readonly NavexConfigurationService $configurations,
        private readonly NavexShipmentPayloadFactory $payloads,
    ) {}

    public function queue(Order $order, string $creationMode): NavexShipment
    {
        return DB::transaction(function () use ($order, $creationMode): NavexShipment {
            $order = Order::query()->with(['items', 'checkoutValues', 'navexShipment'])->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($order->status !== 'confirmee') {
                throw ValidationException::withMessages(['order' => 'Seules les commandes confirmées peuvent être envoyées à Navex.']);
            }
            if (blank($order->customer_governorate)) {
                throw ValidationException::withMessages(['governorate' => 'Le colis n’a pas été envoyé, car le gouvernorat est manquant.']);
            }
            $configuration = $this->configurations->usableForCreation();
            if ($configuration === null) {
                throw ValidationException::withMessages(['configuration' => 'La configuration Navex est incomplète ou le mode d’envoi est désactivé.']);
            }
            if ($creationMode === 'automatic' && $configuration->mode !== 'automatic') {
                throw ValidationException::withMessages(['mode' => 'L’envoi automatique Navex n’est pas activé.']);
            }
            if ($creationMode === 'manual' && $configuration->mode !== 'manual') {
                throw ValidationException::withMessages(['mode' => 'L’envoi manuel Navex n’est pas activé.']);
            }

            $shipment = $order->navexShipment;
            if ($shipment !== null) {
                if (! in_array($shipment->status, [NavexDeliveryStatus::NotSent, NavexDeliveryStatus::SynchronizationError], true)) {
                    throw ValidationException::withMessages(['shipment' => 'Un colis Navex existe déjà pour cette commande.']);
                }
                $attributes = ['status' => NavexDeliveryStatus::PendingSend, 'creation_mode' => $creationMode, 'next_retry_at' => null, 'last_error_classification' => null];
                // A retryable shipment that has never received a tracking code
                // has not been confirmed by Navex. Refresh its encrypted
                // request from the current order so corrected exchange values
                // (and any other editable creation fields) are sent on retry.
                // Once a tracking code exists, keep the original snapshot and
                // never recreate or alter the provider shipment here.
                if ($shipment->tracking_code === null) {
                    $payload = $this->payloads->make($order, $configuration);
                    $attributes['request_snapshot_encrypted'] = Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR));
                }
                $shipment->update($attributes);
            } else {
                $payload = $this->payloads->make($order, $configuration);
                $shipment = NavexShipment::query()->create([
                    'order_id' => $order->id,
                    'navex_configuration_id' => $configuration->id,
                    'status' => NavexDeliveryStatus::PendingSend,
                    'creation_mode' => $creationMode,
                    'request_snapshot_encrypted' => Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR)),
                ]);
                $shipment->statusHistory()->create(['status' => NavexDeliveryStatus::PendingSend, 'recorded_at' => now()]);
            }

            DB::afterCommit(fn () => CreateNavexShipmentJob::dispatch($shipment->public_id)->onQueue('integrations'));

            return $shipment;
        });
    }

    public function retry(Order $order): NavexShipment
    {
        $configuration = $this->configurations->current();
        if ($configuration === null || ! in_array($configuration->mode, ['manual', 'automatic'], true)) {
            throw ValidationException::withMessages(['configuration' => 'La configuration Navex ne permet pas de relancer cet envoi.']);
        }

        return $this->queue($order, $configuration->mode);
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
        if (blank($order->customer_governorate)) {
            $reasons[] = 'Le gouvernorat est manquant.';
        }
        if ($configuration === null || ! $configuration->complete()) {
            $reasons[] = 'La configuration Navex est incomplète.';
        }
        if ($mode === 'disabled') {
            $reasons[] = 'L’intégration Navex est désactivée.';
        }

        return ['ready' => $reasons === [], 'reasons' => $reasons, 'mode' => $mode];
    }
}
