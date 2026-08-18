<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Audit\Actions\RecordAuditEventAction;
use App\Domain\Catalog\Models\Product;
use App\Domain\MetaTracking\Models\MetaEvent;
use App\Domain\MetaTracking\Models\MetaEventAttempt;
use App\Domain\MetaTracking\Services\MetaConfigurationService;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Jobs\DispatchMetaEventJob;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class MetaDiagnosticsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'event_name' => ['nullable', 'in:PageView,ViewContent,Search,AddToCart,InitiateCheckout,Purchase'],
            'capi_state' => ['nullable', 'in:pending,sending,succeeded,temporary_failure,permanent_failure,skipped_no_consent,skipped_tracking_disabled,skipped_no_active_configuration'],
            'browser_state' => ['nullable', 'in:eligible,rendered,attempted,blocked_or_unknown'],
            'marketing_consent' => ['nullable', 'in:true,false,1,0'],
            'synthetic' => ['nullable', 'in:true,false,1,0'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'order_reference' => ['nullable', 'ulid'],
            'product_public_id' => ['nullable', 'ulid'],
            'catalog_mapping' => ['nullable', 'in:complete,partial,missing'],
            'mode' => ['nullable', 'in:test,live'],
            'global_status' => ['nullable', 'in:pair_dispatched,server_only,browser_only,pending,action_required'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);
        $events = MetaEvent::query()
            ->with(['configuration:id,configuration_version,pixel_id,test_mode', 'order:id,public_reference'])
            ->when($filters['event_name'] ?? null, fn ($query, $value) => $query->where('event_name', $value))
            ->when($filters['capi_state'] ?? null, fn ($query, $value) => $query->where('capi_state', $value))
            ->when($filters['browser_state'] ?? null, fn ($query, $value) => $query->where('browser_state', $value))
            ->when(array_key_exists('marketing_consent', $filters), fn ($query) => $query->where('marketing_consent', filter_var($filters['marketing_consent'], FILTER_VALIDATE_BOOLEAN)))
            ->when(array_key_exists('synthetic', $filters), fn ($query) => $query->where('is_synthetic', filter_var($filters['synthetic'], FILTER_VALIDATE_BOOLEAN)))
            ->when($filters['date_from'] ?? null, fn ($query, $value) => $query->whereDate('created_at', '>=', $value))
            ->when($filters['date_to'] ?? null, fn ($query, $value) => $query->whereDate('created_at', '<=', $value))
            ->when($filters['order_reference'] ?? null, fn ($query, $value) => $query->whereHas('order', fn ($orders) => $orders->where('public_reference', $value)))
            ->when($filters['product_public_id'] ?? null, fn ($query, $value) => $query->where('context_summary->product_public_id', $value))
            ->when($filters['catalog_mapping'] ?? null, fn ($query, $value) => $query->where('context_summary->catalog_mapping_state', $value))
            ->when($filters['mode'] ?? null, fn ($query, $value) => $query->whereHas('configuration', fn ($configurations) => $configurations->where('test_mode', $value === 'test')))
            ->when($filters['global_status'] ?? null, fn ($query, $value) => $this->applyGlobalStatusFilter($query, $value))
            ->latest('created_at')
            ->paginate($filters['per_page'] ?? 25);

        $catalogEvents = MetaEvent::query()->whereIn('event_name', ['ViewContent', 'AddToCart', 'InitiateCheckout', 'Purchase']);

        $eventsMissing = (clone $catalogEvents)->where(function ($query): void {
            $query->where('context_summary->catalog_mapping_state', 'missing')->orWhereNull('context_summary->catalog_mapping_state');
        })->count();

        return ApiResponse::success(['data' => $events->getCollection()->map(fn (MetaEvent $event): array => $this->summary($event))->values(), 'meta' => ['current_page' => $events->currentPage(), 'last_page' => $events->lastPage(), 'total' => $events->total(), 'catalogue' => ['products_configured' => Product::query()->whereNotNull('meta_catalog_id')->count(), 'products_unconfigured' => Product::query()->whereNull('meta_catalog_id')->count(), 'events_complete' => (clone $catalogEvents)->where('context_summary->catalog_mapping_state', 'complete')->count(), 'events_partial' => (clone $catalogEvents)->where('context_summary->catalog_mapping_state', 'partial')->count(), 'events_missing' => $eventsMissing]]]);
    }

    /** @param Builder<MetaEvent> $query
     * @return Builder<MetaEvent>
     */
    private function applyGlobalStatusFilter(Builder $query, string $status): Builder
    {
        return match ($status) {
            'pair_dispatched' => $query->where('browser_state', 'attempted')->where('capi_state', 'succeeded'),
            'server_only' => $query->where('browser_state', '!=', 'attempted')->where('capi_state', 'succeeded'),
            'browser_only' => $query->where('browser_state', 'attempted')->where('capi_state', 'permanent_failure'),
            'pending' => $query->whereIn('capi_state', ['pending', 'sending', 'temporary_failure']),
            'action_required' => $query->where('browser_state', '!=', 'attempted')->whereIn('capi_state', ['permanent_failure', 'skipped_tracking_disabled', 'skipped_no_active_configuration']),
            default => $query,
        };
    }

    public function show(MetaEvent $event): JsonResponse
    {
        $event->load(['configuration:id,configuration_version,pixel_id,test_mode', 'order:id,public_reference', 'attempts']);

        return ApiResponse::success([
            ...$this->summary($event),
            'attempts' => $event->attempts->map(fn (MetaEventAttempt $attempt): array => [
                'channel' => $attempt->channel,
                'attempt_number' => $attempt->attempt_number,
                'outcome' => $attempt->outcome,
                'request_sent' => $attempt->request_sent,
                'http_status' => $attempt->http_status,
                'events_received' => $attempt->events_received,
                'error_classification' => $attempt->error_classification,
                'meta_error_code' => $attempt->meta_error_code,
                'meta_error_subcode' => $attempt->meta_error_subcode,
                'safe_message' => $attempt->safe_message,
                'fbtrace_id' => $attempt->fbtrace_id,
                'graph_api_version' => $attempt->graph_api_version,
                'attempted_at' => $this->isoDate($attempt->getAttribute('attempted_at')),
            ])->values(),
            'retry_eligible' => in_array($event->capi_state, ['temporary_failure', 'permanent_failure'], true) && ! $event->is_synthetic,
        ]);
    }

    public function retry(Request $request, MetaEvent $event, RecordAuditEventAction $audit, MetaConfigurationService $configurations): JsonResponse
    {
        $data = $request->validate(['current_password' => ['required', 'string']]);
        $actor = $request->user();
        if (! $actor || ! Hash::check($data['current_password'], $actor->password)) {
            return ApiResponse::error('META_PASSWORD_CONFIRMATION_REQUIRED', 'Le mot de passe actuel est incorrect.', 422);
        }
        if ($event->is_synthetic || ! in_array($event->capi_state, ['temporary_failure', 'permanent_failure'], true)) {
            return ApiResponse::error('META_RETRY_UNAVAILABLE', 'Cet événement ne peut pas être relancé.', 422);
        }

        $activeConfiguration = in_array($event->last_error_classification, ['configuration_invalid', 'token_decryption_failed'], true)
            ? $configurations->active()
            : null;
        $configurationId = $event->meta_configuration_id;
        if ($activeConfiguration !== null) {
            $configurationId = $activeConfiguration->id;
        }
        $event->update([
            'meta_configuration_id' => $configurationId,
            'capi_state' => 'pending',
            'next_retry_at' => null,
            'last_error_classification' => null,
        ]);
        DispatchMetaEventJob::dispatch($event->public_id)->onQueue('meta');
        $audit->handle('meta.event_retry_requested', $event, $actor, after: [
            'event_name' => $event->event_name,
            'event_public_id' => $event->public_id,
            'configuration_rebound' => $activeConfiguration !== null,
            'configuration_version' => $activeConfiguration?->configuration_version,
        ]);

        return ApiResponse::success(['event' => $this->summary($event->fresh() ?? $event)]);
    }

    /** @return array<string, mixed> */
    private function summary(MetaEvent $event): array
    {
        $context = $event->getAttribute('context_summary');
        $context = is_array($context) ? $context : [];

        return [
            'public_id' => $event->public_id,
            'event_id' => $event->event_id,
            'event_name' => $event->event_name,
            'event_time' => $this->isoDate($event->getAttribute('event_time')),
            'is_synthetic' => $event->is_synthetic,
            'marketing_consent' => $event->marketing_consent,
            'configuration_version' => $event->configuration?->configuration_version,
            'mode' => $event->configuration?->test_mode ? 'test' : 'live',
            'pixel_id' => $event->configuration?->pixel_id,
            'source_url' => $event->source_url,
            'browser_state' => $event->browser_state,
            'capi_state' => $event->capi_state,
            'retry_count' => $event->retry_count,
            'last_error_classification' => $event->last_error_classification,
            'capi_delivered_at' => $this->isoDate($event->getAttribute('capi_delivered_at')),
            'deduplication_status' => $this->deduplicationStatus($event),
            'global_status' => $this->globalStatus($event),
            'order_reference' => $event->order?->public_reference,
            'context' => array_intersect_key($context, array_flip(['route_type', 'product_public_id', 'variant_public_id', 'content_ids', 'catalog_mapping_state', 'catalog_mapping_missing_count', 'quantity', 'value_millimes', 'currency', 'search_term', 'result_count', 'item_count'])),
        ];
    }

    private function deduplicationStatus(MetaEvent $event): string
    {
        if ($event->browser_state !== 'attempted') {
            return 'unavailable';
        }

        return $event->capi_state === 'succeeded' ? 'pending_confirmation' : 'pending';
    }

    private function globalStatus(MetaEvent $event): string
    {
        if ($event->capi_state === 'succeeded') {
            return $event->browser_state === 'attempted' ? 'pair_dispatched' : 'server_only';
        }
        if (in_array($event->capi_state, ['pending', 'sending', 'temporary_failure'], true)) {
            return 'pending';
        }

        return $event->browser_state === 'attempted' ? 'browser_only' : 'action_required';
    }

    private function isoDate(mixed $value): ?string
    {
        return $value instanceof CarbonInterface ? $value->toIso8601String() : null;
    }
}
