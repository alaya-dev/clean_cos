<?php

namespace App\Domain\FirstDelivery\Services;

use App\Domain\FirstDelivery\Models\FirstDeliveryShipment;
use Illuminate\Support\Facades\DB;

class FirstDeliveryShipmentAttemptRecorder
{
    public function record(FirstDeliveryShipment $shipment, string $operation, FirstDeliveryResult $result): void
    {
        DB::transaction(function () use ($shipment, $operation, $result): void {
            $locked = FirstDeliveryShipment::query()->whereKey($shipment->id)->lockForUpdate()->firstOrFail();
            $attemptNumber = ((int) $locked->attempts()->where('operation', $operation)->max('attempt_number')) + 1;

            $locked->attempts()->create([
                'operation' => $operation,
                'attempt_number' => $attemptNumber,
                'request_sent' => $result->requestSent,
                'http_status' => $result->httpStatus,
                'outcome' => $result->classification,
                'error_classification' => $result->accepted ? null : $result->classification,
                'safe_message' => $result->safeMessage,
                'duration_ms' => $result->durationMs,
                'attempted_at' => now(),
            ]);
            $locked->increment('attempt_count');
        });
    }
}
