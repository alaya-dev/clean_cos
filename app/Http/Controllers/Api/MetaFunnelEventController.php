<?php

namespace App\Http\Controllers\Api;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\CatalogPriceResolver;
use App\Domain\Commerce\Services\CartQuoteService;
use App\Domain\MetaTracking\Models\MetaEvent;
use App\Domain\MetaTracking\Policies\MetaEventEligibilityPolicy;
use App\Domain\MetaTracking\Services\MarketingConsentService;
use App\Domain\MetaTracking\Services\MetaCatalogIdentifierResolver;
use App\Domain\MetaTracking\Services\MetaEventFactory;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Jobs\DispatchMetaEventJob;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MetaFunnelEventController extends Controller
{
    public function __invoke(
        Request $request,
        MetaEventEligibilityPolicy $eligibility,
        MarketingConsentService $consent,
        MetaEventFactory $events,
        CartQuoteService $quotes,
        MetaCatalogIdentifierResolver $catalogIdentifiers,
        CatalogPriceResolver $prices,
    ): JsonResponse {
        $data = $request->validate([
            'event_name' => ['required', 'in:PageView,ViewContent,Search,AddToCart,InitiateCheckout'],
            'source_url' => ['required', 'url', 'max:500'],
            'route_type' => ['nullable', 'in:home,products,category,product,search,cart,checkout,confirmation,static'],
            'checkout_source' => ['nullable', 'in:cart,buy_now'],
            'product_public_id' => ['nullable', 'ulid'],
            'variant_public_id' => ['nullable', 'ulid'],
            'quantity' => ['nullable', 'integer', 'between:1,99'],
            'search_term' => ['nullable', 'string', 'max:100'],
            'result_count' => ['nullable', 'integer', 'between:0,1000'],
            'items' => ['nullable', 'array', 'min:1', 'max:100'],
            'items.*.product_public_id' => ['required_with:items', 'ulid'],
            'items.*.variant_public_id' => ['nullable', 'ulid'],
            'items.*.quantity' => ['required_with:items', 'integer', 'between:1,99'],
        ]);

        if (! $eligibility->eligible($request) || ! $this->isStorefrontUrl($data['source_url'], $request)) {
            return ApiResponse::success(['event' => null]);
        }

        $context = $this->contextFor($data, $quotes, $catalogIdentifiers, $prices);
        if ($context === null) {
            return ApiResponse::success(['event' => null]);
        }

        $event = $events->create(
            $data['event_name'],
            $consent->current($request)['policy_version'],
            $data['source_url'],
            $context,
        );
        if (! $event) {
            return ApiResponse::success(['event' => null]);
        }

        DispatchMetaEventJob::dispatch($event->public_id)->onQueue('meta');

        return ApiResponse::success(['event' => $this->browserEvent($event)]);
    }

    public function browserAttempt(Request $request, MetaEvent $event, MetaEventEligibilityPolicy $eligibility): JsonResponse
    {
        if (! $event->marketing_consent || ! $eligibility->eligible($request)) {
            return ApiResponse::success(['recorded' => false]);
        }

        if ($event->browser_state === 'eligible') {
            $event->update(['browser_state' => 'attempted']);
        }

        return ApiResponse::success(['recorded' => $event->fresh()?->browser_state === 'attempted']);
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    private function contextFor(array $data, CartQuoteService $quotes, MetaCatalogIdentifierResolver $catalogIdentifiers, CatalogPriceResolver $prices): ?array
    {
        return match ($data['event_name']) {
            'PageView' => ['route_type' => $data['route_type'] ?? 'static'],
            'Search' => $this->searchContext($data),
            'ViewContent', 'AddToCart' => $this->productContext($data, $catalogIdentifiers, $prices, $data['event_name']),
            'InitiateCheckout' => $this->checkoutContext($data, $quotes),
            default => null,
        };
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    private function searchContext(array $data): ?array
    {
        $term = preg_replace('/[^\pL\pN\s-]/u', '', trim((string) ($data['search_term'] ?? '')));
        if (! is_string($term) || $term === '') {
            return null;
        }

        return ['search_term' => mb_substr(preg_replace('/\s+/u', ' ', $term) ?? $term, 0, 100), 'result_count' => (int) ($data['result_count'] ?? 0)];
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    private function productContext(array $data, MetaCatalogIdentifierResolver $catalogIdentifiers, CatalogPriceResolver $prices, string $eventName): ?array
    {
        $productId = $data['product_public_id'] ?? null;
        if (! is_string($productId)) {
            return null;
        }
        $product = Product::query()->public()->with('variants')->where('public_id', $productId)->first();
        if (! $product) {
            return null;
        }
        $quantity = (int) ($data['quantity'] ?? 1);
        $variantId = $data['variant_public_id'] ?? null;
        $variant = null;
        if ($product->has_variants) {
            $variant = is_string($variantId) ? $product->variants->firstWhere('public_id', $variantId) : null;
            if (! $variant || ! $variant->is_active || $variant->stock_quantity < $quantity) {
                return null;
            }
        } elseif ($variantId !== null || ($product->stock_quantity ?? 0) < $quantity) {
            return null;
        }
        $unitPrice = $prices->resolve($product, $variant)['effective_millimes'];
        $value = $unitPrice * $quantity;
        $catalog = $catalogIdentifiers->resolve($product, $variant);
        if (! $catalog->mapped()) {
            Log::warning('meta_catalog_mapping_missing', ['product_public_id' => $product->public_id, 'event_type' => $eventName, 'mapping_state' => 'missing']);
        }

        return array_filter([
            'product_public_id' => $product->public_id,
            'variant_public_id' => is_string($variantId) ? $variantId : null,
            'content_ids' => $catalog->identifier === null ? [] : [$catalog->identifier],
            'contents' => $catalog->identifier === null ? [] : [['id' => $catalog->identifier, 'quantity' => $quantity, 'item_price_millimes' => $unitPrice]],
            'catalog_mapping_state' => $catalog->mapped() ? 'complete' : 'missing',
            'catalog_mapping_missing_count' => $catalog->mapped() ? 0 : 1,
            'quantity' => $quantity,
            'value_millimes' => $value,
            'currency' => 'TND',
        ], static fn (mixed $value): bool => $value !== null);
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    private function checkoutContext(array $data, CartQuoteService $quotes): ?array
    {
        $items = $data['items'] ?? null;
        if (! is_array($items)) {
            return null;
        }
        $quote = $quotes->quote($items);
        if (($quote['can_checkout'] ?? false) !== true) {
            return null;
        }
        $lines = $quote['items'] ?? [];
        if (! is_array($lines)) {
            return null;
        }

        return [
            'checkout_source' => $data['checkout_source'] ?? 'cart',
            'content_ids' => array_values(array_filter(array_map(static fn (mixed $line): ?string => is_array($line) && is_string($line['meta_catalog_id'] ?? null) ? $line['meta_catalog_id'] : null, $lines))),
            'contents' => array_values(array_filter(array_map(static fn (mixed $line): ?array => is_array($line) && is_string($line['meta_catalog_id'] ?? null) ? ['id' => $line['meta_catalog_id'], 'quantity' => (int) ($line['quantity_requested'] ?? 1), 'item_price_millimes' => (int) data_get($line, 'effective_unit_price.millimes', 0)] : null, $lines))),
            'catalog_mapping_state' => $this->mappingState($lines),
            'catalog_mapping_missing_count' => count(array_filter($lines, static fn (mixed $line): bool => ! is_array($line) || ! is_string($line['meta_catalog_id'] ?? null))),
            'item_count' => array_sum(array_map(static fn (mixed $line): int => is_array($line) ? (int) ($line['quantity_requested'] ?? 0) : 0, $lines)),
            'value_millimes' => (int) data_get($quote, 'pricing.total.millimes', 0),
            'currency' => 'TND',
        ];
    }

    /** @param array<int, mixed> $lines */
    private function mappingState(array $lines): string
    {
        $mapped = count(array_filter($lines, static fn (mixed $line): bool => is_array($line) && is_string($line['meta_catalog_id'] ?? null)));

        return $mapped === count($lines) ? 'complete' : ($mapped > 0 ? 'partial' : 'missing');
    }

    /** @return array<string, mixed> */
    private function browserEvent(MetaEvent $event): array
    {
        $eventTime = $event->getAttribute('event_time');
        if (! $eventTime instanceof CarbonInterface) {
            throw new \LogicException('Meta events require an original event timestamp.');
        }

        return [
            'public_id' => $event->public_id,
            'event_name' => $event->event_name,
            'event_id' => $event->event_id,
            'event_time' => $eventTime->toIso8601String(),
            'source_url' => $event->source_url,
            'context' => $event->context_summary,
        ];
    }

    private function isStorefrontUrl(string $url, Request $request): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return is_string($host)
            && strcasecmp($host, $request->getHost()) === 0
            && in_array($scheme, ['http', 'https'], true)
            && (app()->environment(['local', 'testing']) || $scheme === 'https');
    }
}
