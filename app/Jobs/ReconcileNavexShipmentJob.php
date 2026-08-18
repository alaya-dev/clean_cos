<?php

namespace App\Jobs;

use App\Domain\Navex\Enums\NavexDeliveryStatus;
use App\Domain\Navex\Models\NavexShipment;
use App\Domain\Navex\Services\NavexClient;
use App\Domain\Navex\Services\NavexShipmentAttemptRecorder;
use App\Domain\Navex\Services\NavexShipmentPayloadFactory;
use App\Domain\Navex\Services\NavexShipmentStateService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

class ReconcileNavexShipmentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(public readonly string $shipmentPublicId)
    {
        $this->onQueue('integrations');
    }

    public function handle(NavexClient $client, NavexShipmentAttemptRecorder $attempts, NavexShipmentStateService $states, NavexShipmentPayloadFactory $payloads): void
    {
        $lock = Cache::lock('pc:navex:reconcile:'.$this->shipmentPublicId, 90);
        if (! $lock->get()) {
            $this->release(30);

            return;
        }
        try {
            $shipment = NavexShipment::query()->with(['configuration', 'order.items'])->where('public_id', $this->shipmentPublicId)->first();
            $needsBarcodeReconciliation = $shipment?->status === NavexDeliveryStatus::Accepted && blank($shipment->tracking_code);
            if ($shipment === null || (! $needsBarcodeReconciliation && $shipment->status !== NavexDeliveryStatus::UncertainResult) || $shipment->configuration === null) {
                return;
            }
            $order = $shipment->order;
            if ($order === null) {
                $states->mark($shipment, NavexDeliveryStatus::ManualActionRequired, 'order_missing');

                return;
            }
            $result = $client->pending($shipment->configuration);
            $attempts->record($shipment, 'reconciliation', $result);
            if (! $result->accepted || ! is_array($result->payload)) {
                if ($needsBarcodeReconciliation) {
                    // Navex already acknowledged creation. A delayed pending-list
                    // response must never turn that acknowledgement into an error.
                    return;
                }
                $states->mark($shipment, NavexDeliveryStatus::ManualActionRequired, $result->classification);

                return;
            }
            $snapshot = $this->requestSnapshot($shipment);
            $expectedDesignation = is_string($snapshot['designation'] ?? null) && $snapshot['designation'] !== ''
                ? $snapshot['designation']
                : $payloads->designation($order);
            $expectedPrice = is_string($snapshot['prix'] ?? null) && $snapshot['prix'] !== ''
                ? $snapshot['prix']
                : number_format($order->total_millimes / 1000, 3, '.', '');
            $matches = collect((array) ($result->payload['colis'] ?? []))->filter(function (mixed $item) use ($expectedDesignation, $expectedPrice): bool {
                if (! is_array($item)) {
                    return false;
                }

                return ($item['designation'] ?? null) === $expectedDesignation && (string) ($item['prix'] ?? '') === $expectedPrice && filled($item['code_barre'] ?? null);
            })->values();
            if ($matches->count() === 1) {
                $shipment->update(['tracking_code' => (string) $matches->first()['code_barre'], 'sent_at' => now(), 'last_error_classification' => null]);
                $states->mark($shipment, NavexDeliveryStatus::Accepted);

                return;
            }
            if ($needsBarcodeReconciliation && $matches->isEmpty()) {
                // The parcel is accepted; Navex may expose its barcode later.
                return;
            }
            $states->mark(
                $shipment,
                $matches->isEmpty() ? NavexDeliveryStatus::SynchronizationError : NavexDeliveryStatus::ManualActionRequired,
                $matches->isEmpty() ? 'reconciliation_not_found' : 'reconciliation_ambiguous',
            );
        } finally {
            $lock->release();
        }
    }

    /** @return array<string, mixed> */
    private function requestSnapshot(NavexShipment $shipment): array
    {
        if (! is_string($shipment->request_snapshot_encrypted) || $shipment->request_snapshot_encrypted === '') {
            return [];
        }

        try {
            $snapshot = json_decode(Crypt::decryptString($shipment->request_snapshot_encrypted), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            return [];
        }

        return is_array($snapshot) ? $snapshot : [];
    }
}
