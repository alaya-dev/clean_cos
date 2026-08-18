<?php

namespace App\Domain\Navex\Services;

use App\Domain\Navex\Models\NavexShipment;
use Illuminate\Support\Facades\DB;

class NavexShipmentAttemptRecorder
{
    public function record(NavexShipment $shipment, string $operation, NavexResult $result): void
    {
        DB::transaction(function () use ($shipment, $operation, $result): void {
            $lockedShipment = NavexShipment::query()->whereKey($shipment->id)->lockForUpdate()->firstOrFail();
            $attemptNumber = ((int) $lockedShipment->attempts()->where('operation', $operation)->max('attempt_number')) + 1;

            $lockedShipment->attempts()->create([
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
            $lockedShipment->increment('attempt_count');
        });
    }
}
