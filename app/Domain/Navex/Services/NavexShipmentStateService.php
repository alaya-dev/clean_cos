<?php

namespace App\Domain\Navex\Services;

use App\Domain\Audit\Actions\RecordAuditEventAction;
use App\Domain\Navex\Enums\NavexDeliveryStatus;
use App\Domain\Navex\Models\NavexShipment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class NavexShipmentStateService
{
    public function __construct(private readonly RecordAuditEventAction $audit) {}

    /** @param array<array-key, mixed> $provider */
    public function synchronize(NavexShipment $shipment, array $provider): NavexShipment
    {
        return DB::transaction(function () use ($shipment, $provider): NavexShipment {
            $shipment = NavexShipment::query()->with('order')->whereKey($shipment->id)->lockForUpdate()->firstOrFail();
            $rawStatus = $this->string($provider['etat'] ?? null);
            $rawReason = $this->string($provider['motif'] ?? null);
            $status = $this->normalized($rawStatus, $shipment->status);
            $changed = $shipment->status !== $status || $shipment->raw_status !== $rawStatus || $shipment->raw_reason !== $rawReason;
            $shipment->update([
                'status' => $status,
                'previous_raw_status' => $this->string($provider['pre_etat'] ?? $shipment->raw_status),
                'previous_raw_reason' => $this->string($provider['pre_motif'] ?? $shipment->raw_reason),
                'raw_status' => $rawStatus,
                'raw_reason' => $rawReason,
                'courier_name' => $this->string($provider['livreur'] ?? null),
                'courier_phone' => $this->string($provider['livreur_tel'] ?? null),
                'provider_status_at' => $this->providerDate($provider['date_dernier_statut'] ?? null),
                'last_synchronized_at' => now(),
                'next_retry_at' => null,
                'last_error_classification' => null,
            ]);
            if ($changed) {
                $shipment->statusHistory()->create([
                    'status' => $status,
                    'raw_status' => $rawStatus,
                    'raw_reason' => $rawReason,
                    'provider_status_at' => $shipment->provider_status_at,
                    'recorded_at' => now(),
                ]);
            }
            if ($changed) {
                $this->audit->handle('navex.shipment_synchronized', $shipment, after: [
                    'status' => $status->value,
                    'tracking_code_present' => filled($shipment->tracking_code),
                    'provider_status_received' => filled($rawStatus),
                    'provider_status_unmapped' => NavexDeliveryStatus::fromProviderStatus($rawStatus) === null && filled($rawStatus),
                ]);
            }

            return $shipment->fresh(['order', 'statusHistory']) ?? $shipment;
        });
    }

    public function mark(NavexShipment $shipment, NavexDeliveryStatus $status, ?string $error = null): NavexShipment
    {
        return DB::transaction(function () use ($shipment, $status, $error): NavexShipment {
            $shipment = NavexShipment::query()->whereKey($shipment->id)->lockForUpdate()->firstOrFail();
            $changed = $shipment->status !== $status || $shipment->last_error_classification !== $error;
            $shipment->update(['status' => $status, 'last_error_classification' => $error]);
            if ($changed) {
                $shipment->statusHistory()->create(['status' => $status, 'raw_status' => $shipment->raw_status, 'raw_reason' => $shipment->raw_reason, 'recorded_at' => now()]);
                $this->audit->handle('navex.shipment_status_changed', $shipment, after: [
                    'status' => $status->value,
                    'error_classification' => $error,
                    'tracking_code_present' => filled($shipment->tracking_code),
                ]);
            }

            return $shipment->fresh() ?? $shipment;
        });
    }

    private function normalized(?string $rawStatus, NavexDeliveryStatus $current): NavexDeliveryStatus
    {
        return match (mb_strtolower(trim((string) $rawStatus))) {
            'livrer paye' => NavexDeliveryStatus::DeliveredPaid,
            'retourné', 'retourne' => NavexDeliveryStatus::Returned,
            'supprime', 'supprimé' => NavexDeliveryStatus::Cancelled,
            'en attente' => NavexDeliveryStatus::Pending,
            'en cours' => NavexDeliveryStatus::InDelivery,
            default => $this->fallbackStatus($rawStatus, $current),
        };
    }

    private function fallbackStatus(?string $rawStatus, NavexDeliveryStatus $current): NavexDeliveryStatus
    {
        return filled($rawStatus)
            ? ($current->representsProviderState() ? $current : NavexDeliveryStatus::Accepted)
            : ($current->representsProviderState() ? $current : NavexDeliveryStatus::SynchronizationError);
    }

    private function string(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? mb_substr(trim((string) $value), 0, 500) : null;
    }

    private function providerDate(mixed $value): ?\DateTimeInterface
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
