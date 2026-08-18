<?php

namespace App\Domain\Navex\Actions;

use App\Domain\Navex\Enums\NavexDeliveryStatus;
use App\Domain\Navex\Models\NavexConfiguration;
use App\Domain\Navex\Models\NavexShipment;
use App\Domain\Navex\Services\NavexClient;
use App\Domain\Navex\Services\NavexShipmentAttemptRecorder;
use App\Domain\Navex\Services\NavexShipmentStateService;
use App\Jobs\ReconcileNavexShipmentJob;
use App\Jobs\SynchronizeNavexShipmentJob;
use Illuminate\Support\Collection;

class SynchronizeNavexShipmentsAction
{
    public function __construct(
        private readonly NavexClient $client,
        private readonly NavexShipmentAttemptRecorder $attempts,
        private readonly NavexShipmentStateService $states,
    ) {}

    public function handle(int $limit, bool $includeOld = false): int
    {
        $pollingCutoff = now()->subDays(90);
        $shipments = NavexShipment::query()
            ->with('configuration')
            ->whereNotNull('tracking_code')
            ->whereNotIn('status', [NavexDeliveryStatus::DeliveredPaid->value, NavexDeliveryStatus::Returned->value, NavexDeliveryStatus::Cancelled->value])
            ->when(! $includeOld, fn ($query) => $query->where(fn ($query) => $query->whereNull('sent_at')->orWhere('sent_at', '>', $pollingCutoff)))
            ->where(fn ($query) => $query->whereNull('next_retry_at')->orWhere('next_retry_at', '<=', now()))
            ->orderBy('last_synchronized_at')
            ->limit($limit)
            ->get();

        $processed = 0;
        foreach ($shipments->groupBy('navex_configuration_id') as $configurationShipments) {
            $configuration = $configurationShipments->first()?->configuration;
            if ($configuration === null) {
                continue;
            }

            $processed += $this->synchronizeConfiguration($configurationShipments, $configuration);
        }

        // Creation can be acknowledged before Navex exposes the barcode. Probe
        // only those accepted parcels on the normal conservative sync cadence;
        // never resend them just to obtain a code.
        $remaining = max(0, $limit - $processed);
        if ($remaining > 0) {
            $recovered = $this->recoverTrackingCodesFromCreationAcknowledgements($remaining);
            $processed += $recovered;
            $remaining -= $recovered;
        }
        if ($remaining > 0) {
            $barcodePending = NavexShipment::query()
                ->where('status', NavexDeliveryStatus::Accepted->value)
                ->whereNull('tracking_code')
                ->orderBy('updated_at')
                ->limit($remaining)
                ->get(['public_id']);
            foreach ($barcodePending as $shipment) {
                ReconcileNavexShipmentJob::dispatch($shipment->public_id)->onQueue('integrations');
            }
            $processed += $barcodePending->count();
        }

        return $processed;
    }

    private function recoverTrackingCodesFromCreationAcknowledgements(int $limit): int
    {
        $shipments = NavexShipment::query()
            ->whereIn('status', [NavexDeliveryStatus::Accepted->value, NavexDeliveryStatus::ManualActionRequired->value])
            ->whereNull('tracking_code')
            ->whereHas('attempts', fn ($query) => $query
                ->where('operation', 'creation')
                ->where('outcome', 'accepted_without_tracking_code'))
            ->with(['attempts' => fn ($query) => $query
                ->where('operation', 'creation')
                ->where('outcome', 'accepted_without_tracking_code')
                ->latest('id')])
            ->orderBy('updated_at')
            ->limit($limit)
            ->get();

        $recovered = 0;
        foreach ($shipments as $shipment) {
            $code = $this->creationAcknowledgementTrackingCode((string) $shipment->attempts->first()?->safe_message);
            if ($code === null) {
                continue;
            }

            $shipment->update([
                'tracking_code' => $code,
                'sent_at' => $shipment->sent_at ?? now(),
                'last_error_classification' => null,
            ]);
            $states = $this->states->mark($shipment, NavexDeliveryStatus::Accepted);
            SynchronizeNavexShipmentJob::dispatch($states->public_id)->onQueue('integrations');
            $recovered++;
        }

        return $recovered;
    }

    private function creationAcknowledgementTrackingCode(string $value): ?string
    {
        $value = trim($value);

        return preg_match('/^\d{12}$/D', $value) === 1 ? $value : null;
    }

    /** @param Collection<int, NavexShipment> $shipments */
    private function synchronizeConfiguration(Collection $shipments, NavexConfiguration $configuration): int
    {
        $result = $this->client->trackMany($configuration, $shipments->pluck('tracking_code')->filter()->values()->all());
        foreach ($shipments as $shipment) {
            $this->attempts->record($shipment, 'batch_tracking', $result);
        }
        if (! $result->accepted || ! is_array($result->payload)) {
            // A failed tracking refresh is not evidence that the provider state
            // changed or that the shipment became unknown. Keep the last known
            // state visible while the attempt recorder retains diagnostics.
            return $shipments->count();
        }
        foreach ((array) ($result->payload['results'] ?? []) as $provider) {
            if (! is_array($provider) || (int) ($provider['status'] ?? 0) !== 1) {
                continue;
            }
            $shipment = $shipments->firstWhere('tracking_code', (string) ($provider['code'] ?? ''));
            if ($shipment instanceof NavexShipment) {
                $this->states->synchronize($shipment, $provider);
            }
        }

        return $shipments->count();
    }
}
