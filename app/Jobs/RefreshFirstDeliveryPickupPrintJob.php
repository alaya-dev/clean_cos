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

class RefreshFirstDeliveryPickupPrintJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly string $pickupPublicId)
    {
        $this->onQueue('integrations');
    }

    public function handle(FirstDeliveryClient $client, FirstDeliveryPickupAttemptRecorder $attempts): void
    {
        $lock = Cache::lock('pc:first-delivery:pickup:print:'.$this->pickupPublicId, 60);
        if (! $lock->get()) {
            $this->release(5);

            return;
        }

        try {
            $pickup = FirstDeliveryPickup::query()->with('configuration')->where('public_id', $this->pickupPublicId)->first();
            if ($pickup === null
                || $pickup->status !== FirstDeliveryPickupStatus::Created
                || $pickup->configuration === null
                || blank($pickup->provider_pickup_id)) {
                return;
            }

            $result = $client->printPickup($pickup->configuration, $pickup->provider_pickup_id);
            $attempts->record($pickup, 'print', $result);

            if ($result->accepted && $result->printUrl !== null) {
                $pickup->update([
                    'print_url' => $result->printUrl,
                    'print_error' => null,
                    'print_refresh_pending' => false,
                    'last_printed_at' => now(),
                ]);

                return;
            }

            if ($result->httpStatus === 429 && $this->attempts() < $this->tries) {
                $this->release(15 * max(1, $this->attempts()));

                return;
            }

            $pickup->update([
                'print_refresh_pending' => false,
                'print_error' => $result->accepted ? 'print_link_missing' : $result->classification,
            ]);
        } finally {
            $lock->release();
        }
    }
}
