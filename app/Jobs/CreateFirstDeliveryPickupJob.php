<?php

namespace App\Jobs;

use App\Domain\FirstDelivery\Enums\FirstDeliveryPickupStatus;
use App\Domain\FirstDelivery\Models\FirstDeliveryPickup;
use App\Domain\FirstDelivery\Services\FirstDeliveryClient;
use App\Domain\FirstDelivery\Services\FirstDeliveryPickupAttemptRecorder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

class CreateFirstDeliveryPickupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    public function __construct(public readonly string $pickupPublicId)
    {
        $this->onQueue('integrations');
    }

    public function handle(FirstDeliveryClient $client, FirstDeliveryPickupAttemptRecorder $attempts): void
    {
        $lock = Cache::lock('pc:first-delivery:pickup:create:'.$this->pickupPublicId, 90);
        if (! $lock->get()) {
            $this->release(10);

            return;
        }

        try {
            $pickup = FirstDeliveryPickup::query()
                ->with(['configuration', 'items'])
                ->where('public_id', $this->pickupPublicId)
                ->first();
            if ($pickup === null || $pickup->status !== FirstDeliveryPickupStatus::Pending || $pickup->configuration === null) {
                return;
            }

            $pickup->update(['status' => FirstDeliveryPickupStatus::Creating, 'retryable' => false]);
            $result = $client->createPickup($pickup->configuration, $pickup->items->pluck('barcode')->all());
            $attempts->record($pickup, 'creation', $result);

            if ($result->accepted && $result->pickupId !== null) {
                $pickup->update([
                    'provider_pickup_id' => $result->pickupId,
                    'status' => FirstDeliveryPickupStatus::Created,
                    'print_url' => $result->printUrl,
                    'confirmed_at' => now(),
                    'last_error' => $result->printUrl === null ? 'print_link_missing' : null,
                    'safe_message' => $result->safeMessage,
                    'retryable' => false,
                ]);

                foreach ($pickup->items as $index => $item) {
                    if ($item->first_delivery_shipment_id !== null) {
                        $shipmentPublicId = $item->shipment()->value('public_id');
                        if (is_string($shipmentPublicId)) {
                            SynchronizeFirstDeliveryShipmentJob::dispatch($shipmentPublicId)
                                ->delay(now()->addSeconds($index * 2))
                                ->onQueue('integrations');
                        }
                    }
                }

                return;
            }

            if ($result->httpStatus === 429 && $this->attempts() < $this->tries) {
                $pickup->update([
                    'status' => FirstDeliveryPickupStatus::Pending,
                    'last_error' => 'rate_limited',
                    'safe_message' => $result->safeMessage,
                ]);
                $this->release(min(120, 15 * max(1, $this->attempts())));

                return;
            }

            $uncertain = $result->uncertain || $result->accepted || ($result->httpStatus !== null && $result->httpStatus >= 500);
            $pickup->update([
                'status' => $uncertain ? FirstDeliveryPickupStatus::UncertainResult : FirstDeliveryPickupStatus::Failed,
                'retryable' => ! $uncertain,
                'last_error' => $result->classification,
                'safe_message' => $result->safeMessage,
            ]);
        } finally {
            $lock->release();
        }
    }
}
