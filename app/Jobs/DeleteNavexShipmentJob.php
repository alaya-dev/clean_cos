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

class DeleteNavexShipmentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(public readonly string $shipmentPublicId)
    {
        $this->onQueue('integrations');
    }

    public function handle(NavexClient $client, NavexShipmentAttemptRecorder $attempts, NavexShipmentStateService $states): void
    {
        $lock = Cache::lock('pc:navex:delete:'.$this->shipmentPublicId, 90);
        if (! $lock->get()) {
            $this->release(15);

            return;
        }
        try {
            $shipment = NavexShipment::query()->with('configuration')->where('public_id', $this->shipmentPublicId)->first();
            if ($shipment === null || $shipment->status !== NavexDeliveryStatus::CancellationPending || blank($shipment->tracking_code) || $shipment->configuration === null) {
                return;
            }
            $result = $client->delete($shipment->configuration, $shipment->tracking_code);
            $queueAttempt = max(1, $this->attempts());
            $attempts->record($shipment, 'deletion', $result);
            if ($result->accepted) {
                $shipment->update(['cancelled_at' => now()]);
                $states->mark($shipment, NavexDeliveryStatus::Cancelled);

                return;
            }
            if ($result->temporary && $queueAttempt < $this->tries) {
                $delay = min(900, 60 * (2 ** ($queueAttempt - 1)));
                $shipment->update([
                    'next_retry_at' => now()->addSeconds($delay),
                    'last_error_classification' => $result->classification,
                ]);
                $this->release($delay);

                return;
            }
            $states->mark($shipment, NavexDeliveryStatus::ManualActionRequired, $result->classification);
        } finally {
            $lock->release();
        }
    }
}
