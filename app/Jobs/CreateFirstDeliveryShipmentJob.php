<?php

namespace App\Jobs;

use App\Domain\FirstDelivery\Enums\FirstDeliveryStatus;
use App\Domain\FirstDelivery\Models\FirstDeliveryShipment;
use App\Domain\FirstDelivery\Services\FirstDeliveryClient;
use App\Domain\FirstDelivery\Services\FirstDeliveryShipmentAttemptRecorder;
use App\Domain\FirstDelivery\Services\FirstDeliveryShipmentStateService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Throwable;

class CreateFirstDeliveryShipmentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly string $shipmentPublicId)
    {
        $this->onQueue('integrations');
    }

    public function handle(
        FirstDeliveryClient $client,
        FirstDeliveryShipmentAttemptRecorder $attempts,
        FirstDeliveryShipmentStateService $states,
    ): void {
        $lock = Cache::lock('pc:first-delivery:create:'.$this->shipmentPublicId, 90);
        if (! $lock->get()) {
            $this->release(15);

            return;
        }

        try {
            $shipment = FirstDeliveryShipment::query()
                ->with('configuration')
                ->where('public_id', $this->shipmentPublicId)
                ->first();
            $configuration = $shipment?->configuration;
            if ($shipment === null || $shipment->local_status !== FirstDeliveryStatus::PendingSend || $configuration === null) {
                return;
            }

            $shipment = $states->mark($shipment, FirstDeliveryStatus::Sending);
            try {
                $snapshot = $shipment->request_snapshot_encrypted
                    ? json_decode(Crypt::decryptString($shipment->request_snapshot_encrypted), true, flags: JSON_THROW_ON_ERROR)
                    : null;
            } catch (Throwable) {
                $snapshot = null;
            }
            if (! is_array($snapshot)) {
                $states->mark($shipment, FirstDeliveryStatus::ManualActionRequired, 'request_snapshot_invalid');

                return;
            }

            $result = $client->createOrder($configuration, $snapshot);
            $queueAttempt = max(1, $this->attempts());
            $attempts->record($shipment, 'creation', $result);

            if ($result->accepted) {
                $shipment->update([
                    'barcode' => $result->barcode,
                    'print_url' => $result->printUrl,
                    'sent_at' => now(),
                    'last_error' => $result->barcode === null ? 'barcode_missing' : null,
                ]);
                if ($result->barcode === null) {
                    $states->mark($shipment, FirstDeliveryStatus::ManualActionRequired, 'barcode_missing');

                    return;
                }

                $states->mark($shipment, FirstDeliveryStatus::Accepted);
                SynchronizeFirstDeliveryShipmentJob::dispatch($shipment->public_id)->onQueue('integrations');

                return;
            }

            if ($result->uncertain) {
                $states->mark($shipment, FirstDeliveryStatus::UncertainResult, $result->classification);

                return;
            }

            if ($result->temporary && $queueAttempt < $this->tries) {
                $delay = min(900, 60 * (2 ** ($queueAttempt - 1)));
                $shipment->update([
                    'local_status' => FirstDeliveryStatus::PendingSend,
                    'next_retry_at' => now()->addSeconds($delay),
                    'last_error' => $result->classification,
                ]);
                $this->release($delay);

                return;
            }

            $states->mark($shipment, FirstDeliveryStatus::SynchronizationError, $result->classification);
        } finally {
            $lock->release();
        }
    }
}
