<?php

namespace App\Domain\FirstDelivery\Services;

use App\Domain\FirstDelivery\Enums\FirstDeliveryStatus;
use App\Domain\FirstDelivery\Models\FirstDeliveryShipment;
use Illuminate\Support\Facades\DB;

class FirstDeliveryShipmentStateService
{
    /** @param array<string, mixed> $provider */
    public function synchronize(FirstDeliveryShipment $shipment, array $provider): FirstDeliveryShipment
    {
        return DB::transaction(function () use ($shipment, $provider): FirstDeliveryShipment {
            $shipment = FirstDeliveryShipment::query()->whereKey($shipment->id)->lockForUpdate()->firstOrFail();
            $stateValue = $provider['state'] ?? null;
            $remoteState = $this->text($stateValue);
            $remoteCode = FirstDeliveryStatus::providerCode($stateValue);
            $status = FirstDeliveryStatus::fromProviderState($stateValue)
                ?? $this->fallback($remoteState, $shipment->local_status);
            $changed = $shipment->local_status !== $status
                || $shipment->remote_state_code !== $remoteCode
                || $shipment->remote_state !== $remoteState;

            $shipment->update([
                'local_status' => $status,
                'remote_state_code' => $remoteCode,
                'remote_state' => $remoteState,
                'last_synced_at' => now(),
                'next_retry_at' => null,
                'last_error' => null,
            ]);

            if ($changed) {
                $shipment->statusHistory()->create([
                    'local_status' => $status,
                    'remote_state_code' => $remoteCode,
                    'remote_state' => $remoteState,
                    'recorded_at' => now(),
                ]);
            }

            return $shipment->fresh(['statusHistory']) ?? $shipment;
        });
    }

    public function mark(
        FirstDeliveryShipment $shipment,
        FirstDeliveryStatus $status,
        ?string $error = null,
    ): FirstDeliveryShipment {
        return DB::transaction(function () use ($shipment, $status, $error): FirstDeliveryShipment {
            $shipment = FirstDeliveryShipment::query()->whereKey($shipment->id)->lockForUpdate()->firstOrFail();
            $changed = $shipment->local_status !== $status || $shipment->last_error !== $error;
            $shipment->update(['local_status' => $status, 'last_error' => $error]);

            if ($changed) {
                $shipment->statusHistory()->create([
                    'local_status' => $status,
                    'remote_state_code' => $shipment->remote_state_code,
                    'remote_state' => $shipment->remote_state,
                    'recorded_at' => now(),
                ]);
            }

            return $shipment->fresh() ?? $shipment;
        });
    }

    private function fallback(?string $remoteState, FirstDeliveryStatus $current): FirstDeliveryStatus
    {
        if (filled($remoteState)) {
            return $current->representsProviderState() ? $current : FirstDeliveryStatus::Accepted;
        }

        return $current->representsProviderState() ? $current : FirstDeliveryStatus::SynchronizationError;
    }

    private function text(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== ''
            ? mb_substr(trim((string) $value), 0, 180)
            : null;
    }
}
