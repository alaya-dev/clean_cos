<?php

namespace App\Domain\FirstDelivery\Services;

use App\Domain\FirstDelivery\Models\FirstDeliveryPickup;
use Illuminate\Support\Facades\DB;

class FirstDeliveryPickupAttemptRecorder
{
    public function record(FirstDeliveryPickup $pickup, string $operation, FirstDeliveryResult $result): void
    {
        DB::transaction(function () use ($pickup, $operation, $result): void {
            $locked = FirstDeliveryPickup::query()->whereKey($pickup->id)->lockForUpdate()->firstOrFail();
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
