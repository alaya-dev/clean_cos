<?php

namespace App\Jobs;

use App\Domain\Navex\Enums\NavexDeliveryStatus;
use App\Domain\Navex\Models\NavexShipment;
use App\Domain\Navex\Services\NavexClient;
use App\Domain\Navex\Services\NavexShipmentAttemptRecorder;
use App\Domain\Navex\Services\NavexShipmentStateService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

class CreateNavexShipmentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly string $shipmentPublicId)
    {
        $this->onQueue('integrations');
    }

    public function handle(NavexClient $client, NavexShipmentAttemptRecorder $attempts, NavexShipmentStateService $states): void
    {
        $lock = Cache::lock('pc:navex:create:'.$this->shipmentPublicId, 90);
        if (! $lock->get()) {
            $this->release(15);

            return;
        }
        try {
            $shipment = NavexShipment::query()->with('configuration')->where('public_id', $this->shipmentPublicId)->first();
            $configuration = $shipment?->configuration;
            if ($shipment === null || $shipment->status !== NavexDeliveryStatus::PendingSend || $configuration === null) {
                return;
            }
            $shipment = $states->mark($shipment, NavexDeliveryStatus::Sending);
            $snapshot = $shipment->request_snapshot_encrypted ? json_decode(Crypt::decryptString($shipment->request_snapshot_encrypted), true, flags: JSON_THROW_ON_ERROR) : null;
            if (! is_array($snapshot)) {
                $states->mark($shipment, NavexDeliveryStatus::ManualActionRequired, 'request_snapshot_invalid');

                return;
            }
            $result = $client->create($configuration, $snapshot);
            $queueAttempt = max(1, $this->attempts());
            $attempts->record($shipment, 'creation', $result);
            if ($result->accepted) {
                $shipment->update([
                    'tracking_code' => $result->trackingCode,
                    'sent_at' => now(),
                    'last_error_classification' => $result->trackingCode === null ? 'tracking_code_pending' : null,
                ]);
                $states->mark($shipment, NavexDeliveryStatus::Accepted, $result->trackingCode === null ? 'tracking_code_pending' : null);
                if ($result->trackingCode !== null) {
                    SynchronizeNavexShipmentJob::dispatch($shipment->public_id)->onQueue('integrations');
                } else {
                    // The documented creation response may not include a barcode.
                    // Reconcile the pending list without ever resending the parcel.
                    ReconcileNavexShipmentJob::dispatch($shipment->public_id)->onQueue('integrations');
                }

                return;
            }
            if ($result->uncertain) {
                $states->mark($shipment, NavexDeliveryStatus::UncertainResult, $result->classification);
                ReconcileNavexShipmentJob::dispatch($shipment->public_id)->onQueue('integrations');

                return;
            }
            if ($result->temporary && $queueAttempt < $this->tries) {
                $delay = min(900, 60 * (2 ** ($queueAttempt - 1)));
                $shipment->update(['status' => NavexDeliveryStatus::PendingSend, 'next_retry_at' => now()->addSeconds($delay), 'last_error_classification' => $result->classification]);
                $this->release($delay);

                return;
            }
            $states->mark($shipment, NavexDeliveryStatus::SynchronizationError, $result->classification);
        } finally {
            $lock->release();
        }
    }
}
