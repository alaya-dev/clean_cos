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

class SynchronizeFirstDeliveryShipmentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 10;

    public function __construct(public readonly string $shipmentPublicId)
    {
        $this->onQueue('integrations');
    }

    public function handle(
        FirstDeliveryClient $client,
        FirstDeliveryShipmentAttemptRecorder $attempts,
        FirstDeliveryShipmentStateService $states,
    ): void {
        $lock = Cache::lock('pc:first-delivery:sync:'.$this->shipmentPublicId, 90);
        if (! $lock->get()) {
            $this->release(2);

            return;
        }

        try {
            $shipment = FirstDeliveryShipment::query()
                ->with('configuration')
                ->where('public_id', $this->shipmentPublicId)
                ->first();
            if ($shipment === null
                || blank($shipment->barcode)
                || $shipment->configuration === null
                || $shipment->local_status->terminal()
                || $shipment->local_status === FirstDeliveryStatus::CancellationPending) {
                return;
            }

            if (! Cache::add('pc:first-delivery:status-rate-limit', true, 2)) {
                $this->release(2);

                return;
            }

            $result = $client->getOrderStatus($shipment->configuration, $shipment->barcode);
            $queueAttempt = max(1, $this->attempts());
            $attempts->record($shipment, 'tracking', $result);
            $provider = data_get($result->payload, 'result');

            if ($result->accepted && is_array($provider) && array_key_exists('state', $provider)) {
                $states->synchronize($shipment, $provider);

                return;
            }

            if (($result->temporary || $result->uncertain) && $queueAttempt < 3) {
                $delay = min(300, 30 * (2 ** ($queueAttempt - 1)));
                $shipment->update([
                    'next_retry_at' => now()->addSeconds($delay),
                    'last_error' => $result->classification,
                ]);
                $this->release($delay);

                return;
            }

            $states->mark(
                $shipment,
                FirstDeliveryStatus::SynchronizationError,
                $result->accepted ? 'tracking_state_missing' : $result->classification,
            );
        } finally {
            $lock->release();
        }
    }
}
