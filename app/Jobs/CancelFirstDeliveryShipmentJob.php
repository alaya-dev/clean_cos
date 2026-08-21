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

class CancelFirstDeliveryShipmentJob implements ShouldQueue
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
        $lock = Cache::lock('pc:first-delivery:cancel:'.$this->shipmentPublicId, 90);
        if (! $lock->get()) {
            $this->release(15);

            return;
        }

        try {
            $shipment = FirstDeliveryShipment::query()
                ->with('configuration')
                ->where('public_id', $this->shipmentPublicId)
                ->first();
            if ($shipment === null
                || $shipment->local_status !== FirstDeliveryStatus::CancellationPending
                || blank($shipment->barcode)
                || $shipment->configuration === null) {
                return;
            }

            $result = $client->cancelOrder($shipment->configuration, $shipment->barcode);
            $queueAttempt = max(1, $this->attempts());
            $attempts->record($shipment, 'cancellation', $result);
            $cancelled = collect((array) data_get($result->payload, 'result', []))
                ->contains(fn (mixed $barcode): bool => (string) $barcode === $shipment->barcode);

            if ($result->accepted && $cancelled) {
                $shipment->update([
                    'cancelled_at' => now(),
                    'remote_state_code' => 6,
                    'remote_state' => 'Supprimé',
                    'last_synced_at' => now(),
                ]);
                $states->mark($shipment, FirstDeliveryStatus::Cancelled);

                return;
            }

            if ($result->temporary && $queueAttempt < $this->tries) {
                $delay = min(600, 60 * (2 ** ($queueAttempt - 1)));
                $shipment->update([
                    'next_retry_at' => now()->addSeconds($delay),
                    'last_error' => $result->classification,
                ]);
                $this->release($delay);

                return;
            }

            $states->mark(
                $shipment,
                FirstDeliveryStatus::ManualActionRequired,
                $result->accepted ? 'cancellation_not_confirmed' : $result->classification,
            );
        } finally {
            $lock->release();
        }
    }
}
