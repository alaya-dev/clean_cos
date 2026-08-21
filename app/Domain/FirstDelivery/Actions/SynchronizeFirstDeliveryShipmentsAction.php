<?php

namespace App\Domain\FirstDelivery\Actions;

use App\Domain\FirstDelivery\Enums\FirstDeliveryStatus;
use App\Domain\FirstDelivery\Models\FirstDeliveryShipment;
use App\Jobs\SynchronizeFirstDeliveryShipmentJob;

class SynchronizeFirstDeliveryShipmentsAction
{
    public function handle(int $limit, bool $includeOld = false): int
    {
        $pollingCutoff = now()->subDays(90);
        $shipments = FirstDeliveryShipment::query()
            ->whereNotNull('barcode')
            ->whereNotIn('local_status', [
                FirstDeliveryStatus::Delivered->value,
                FirstDeliveryStatus::Cancelled->value,
                FirstDeliveryStatus::FinalReturn->value,
                FirstDeliveryStatus::CancellationPending->value,
                FirstDeliveryStatus::UncertainResult->value,
                FirstDeliveryStatus::ManualActionRequired->value,
            ])
            ->when(! $includeOld, fn ($query) => $query
                ->where(fn ($query) => $query->whereNull('sent_at')->orWhere('sent_at', '>', $pollingCutoff)))
            ->where(fn ($query) => $query->whereNull('next_retry_at')->orWhere('next_retry_at', '<=', now()))
            ->orderBy('last_synced_at')
            ->limit(max(1, $limit))
            ->get(['public_id']);

        foreach ($shipments as $index => $shipment) {
            SynchronizeFirstDeliveryShipmentJob::dispatch($shipment->public_id)
                ->delay(now()->addSeconds($index * 2))
                ->onQueue('integrations');
        }

        return $shipments->count();
    }
}
