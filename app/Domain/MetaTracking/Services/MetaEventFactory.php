<?php

namespace App\Domain\MetaTracking\Services;

use App\Domain\Commerce\Models\Order;
use App\Domain\MetaTracking\Models\MetaEvent;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

class MetaEventFactory
{
    public function __construct(
        private readonly MetaConfigurationService $configurations,
        private readonly Request $request,
        private readonly MetaAttributionContextFactory $attribution,
    ) {}

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>|null  $attributionOverride
     */
    public function create(string $eventName, int $consentPolicyVersion, string $sourceUrl, array $context = [], ?Order $order = null, ?string $phone = null, ?array $attributionOverride = null): ?MetaEvent
    {
        return $this->createEvent($eventName, $consentPolicyVersion, $sourceUrl, $context, $order, $phone, true, $attributionOverride);
    }

    /**
     * Create a server-only Purchase with the public storefront URL for a
     * trusted back-office order. It deliberately omits the operator's browser
     * attribution: that attribution belongs to the staff session, not the
     * customer.
     *
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>|null  $attribution
     */
    public function createManualPurchase(int $consentPolicyVersion, array $context, Order $order, string $phone, ?array $attribution = null): ?MetaEvent
    {
        return $this->createEvent('Purchase', $consentPolicyVersion, (string) config('app.url'), $context, $order, $phone, false, $attribution);
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>|null  $attributionOverride
     */
    private function createEvent(string $eventName, int $consentPolicyVersion, string $sourceUrl, array $context, ?Order $order, ?string $phone, bool $captureRequestAttribution, ?array $attributionOverride = null): ?MetaEvent
    {
        if (! in_array($eventName, MetaEvent::EVENT_NAMES, true)) {
            throw new \InvalidArgumentException('Unsupported Meta event.');
        }

        $configuration = $this->configurations->active();
        if (! $configuration) {
            return null;
        }

        $summary = $this->sanitizeContext($context);
        $attributes = [
            'event_name' => $eventName,
            'order_id' => $order?->id,
            'meta_configuration_id' => $configuration->id,
            'event_time' => now(),
            'consent_policy_version' => $consentPolicyVersion,
            'marketing_consent' => true,
            'source_url' => $this->sanitizeUrl($sourceUrl),
            'context_summary' => $summary,
            'user_data_encrypted' => $this->encryptedUserData($phone, $captureRequestAttribution, $attributionOverride),
            'payload_hash' => hash('sha256', json_encode([$eventName, $order?->id, $summary], JSON_THROW_ON_ERROR)),
        ];

        try {
            return MetaEvent::query()->create($attributes);
        } catch (QueryException $exception) {
            if ($eventName !== 'Purchase' || ! $order || ! $this->isDuplicatePurchase($exception)) {
                throw $exception;
            }

            return MetaEvent::query()->where('order_id', $order->id)->where('event_name', 'Purchase')->first();
        }
    }

    private function isDuplicatePurchase(QueryException $exception): bool
    {
        return str_contains((string) $exception->getCode(), '23000') || str_contains($exception->getMessage(), 'Duplicate');
    }

    /** @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function sanitizeContext(array $context): array
    {
        $allowed = ['route_type', 'checkout_source', 'manual_order_source', 'action_source', 'product_public_id', 'variant_public_id', 'content_ids', 'contents', 'catalog_mapping_state', 'catalog_mapping_missing_count', 'quantity', 'value_millimes', 'currency', 'search_term', 'result_count', 'item_count', 'order_reference'];

        return array_filter(
            $context,
            static fn (mixed $value, string $key): bool => in_array($key, $allowed, true) && (is_scalar($value) || is_array($value)),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    private function sanitizeUrl(string $url): string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['host'])) {
            return '';
        }

        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return ($parts['scheme'] ?? 'https').'://'.$parts['host'].$port.($parts['path'] ?? '/');
    }

    /** @param array<string, mixed>|null $attributionOverride */
    private function encryptedUserData(?string $phone, bool $captureRequestAttribution, ?array $attributionOverride = null): ?string
    {
        $userData = $attributionOverride !== null
            ? array_filter(array_merge($attributionOverride, ['ph' => $this->attribution->hashTunisianPhone($phone)]))
            : ($captureRequestAttribution
            ? $this->attribution->capture($this->request, $phone)
            : array_filter(['ph' => $this->attribution->hashTunisianPhone($phone)]));

        return $userData === [] ? null : Crypt::encryptString(json_encode($userData, JSON_THROW_ON_ERROR));
    }
}
