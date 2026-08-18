<?php

namespace App\Domain\Commerce\Actions;

use App\Domain\Catalog\Models\InventoryMovement;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\CatalogPriceResolver;
use App\Domain\Checkout\Actions\ResolveCheckoutSubmissionAction;
use App\Domain\Checkout\Models\CheckoutIdempotencyRecord;
use App\Domain\Checkout\Services\ShippingCalculator;
use App\Domain\Commerce\Exceptions\CheckoutConflictException;
use App\Domain\Commerce\Models\CheckoutField;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Services\CheckoutDraftService;
use App\Domain\Commerce\Services\CustomerHistoryService;
use App\Domain\Commerce\Services\OrderExchangeDetails;
use App\Domain\MetaTracking\Policies\MetaPurchaseEligibilityPolicy;
use App\Domain\MetaTracking\Services\MarketingConsentService;
use App\Domain\MetaTracking\Services\MetaCatalogIdentifierResolver;
use App\Domain\MetaTracking\Services\MetaEventFactory;
use App\Domain\Promotions\Exceptions\PromoCodeUnavailable;
use App\Domain\Promotions\Services\PromoCodeService;
use App\Domain\Settings\Services\StoreSettings;
use App\Jobs\DispatchMetaEventJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CreateGuestOrderAction
{
    public function __construct(
        private readonly ShippingCalculator $shippingCalculator,
        private readonly ResolveCheckoutSubmissionAction $resolveCheckoutSubmissionAction,
        private readonly PromoCodeService $promoCodes,
        private readonly StoreSettings $settings,
        private readonly MetaPurchaseEligibilityPolicy $metaPurchaseEligibility,
        private readonly MarketingConsentService $marketingConsent,
        private readonly MetaEventFactory $metaEvents,
        private readonly MetaCatalogIdentifierResolver $catalogIdentifiers,
        private readonly CatalogPriceResolver $prices,
        private readonly Request $request,
        private readonly OrderExchangeDetails $exchangeDetails,
        private readonly CustomerHistoryService $customers,
        private readonly CheckoutDraftService $drafts,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array{order: Order, replayed: bool}
     */
    public function handle(array $data, string $idempotencyKey): array
    {
        return DB::transaction(function () use ($data, $idempotencyKey): array {
            $payloadHash = hash('sha256', json_encode($this->canonicalize($data), JSON_THROW_ON_ERROR));
            $existing = CheckoutIdempotencyRecord::query()->with('order.items', 'order.checkoutValues')->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                if (! hash_equals($existing->canonical_payload_hash, $payloadHash)) {
                    throw new CheckoutConflictException('CHECKOUT_IDEMPOTENCY_CONFLICT', 'Cette demande de commande ne correspond pas à la précédente tentative.');
                }

                $existingOrder = $existing->order;
                abort_unless($existingOrder !== null, 500);

                return ['order' => $existingOrder->load('items', 'checkoutValues'), 'replayed' => true];
            }
            $draft = isset($data['draft_token']) ? $this->drafts->findForUpdate((string) $data['draft_token']) : null;
            if ($draft?->order_id !== null) {
                return ['order' => Order::query()->with('items', 'checkoutValues')->findOrFail($draft->order_id), 'replayed' => true];
            }
            $resolved = $this->resolveCheckoutSubmissionAction->handle($data);
            $exchange = $this->exchangeDetails->fromCheckoutCustomer($resolved['customer']);
            $fields = CheckoutField::query()->where('is_active', true)->orderBy('sort_order')->get();
            $productIds = array_values(array_unique(array_column($data['items'], 'product_public_id')));
            sort($productIds);
            $products = Product::query()->with(['variants.values.productOptionGroup', 'images' => fn ($query) => $query->where('processing_status', 'ready')->orderByDesc('is_primary')])
                ->whereIn('public_id', $productIds)->orderBy('id')->lockForUpdate()->get()->keyBy('public_id');
            $lines = [];
            $subtotal = 0;
            $discount = 0;
            foreach ($data['items'] as $item) {
                /** @var Product|null $product */
                $product = $products->get($item['product_public_id']);
                if (! $product || ! $product->is_active || ! $product->category()->where('is_active', true)->exists()) {
                    throw new CheckoutConflictException('PRODUCT_UNAVAILABLE', 'Un produit de votre panier n’est plus disponible.');
                }
                $variant = null;
                if ($product->has_variants) {
                    $variant = $product->variants->firstWhere('public_id', $item['variant_public_id']);
                    if (! $variant || ! $variant->is_active) {
                        throw new CheckoutConflictException('VARIANT_UNAVAILABLE', 'Une variante de votre panier n’est plus disponible.');
                    }
                    if ($variant->stock_quantity < $item['quantity']) {
                        throw new CheckoutConflictException('INSUFFICIENT_STOCK', 'Le stock disponible a changé.');
                    }
                } elseif ($item['variant_public_id'] || ($product->stock_quantity ?? 0) < $item['quantity']) {
                    throw new CheckoutConflictException('INSUFFICIENT_STOCK', 'Le stock disponible a changé.');
                }
                $price = $this->prices->resolve($product, $variant);
                $regular = $price['regular_millimes'];
                $effective = $price['effective_millimes'];
                $subtotal += $effective * $item['quantity'];
                $discount += ($regular - $effective) * $item['quantity'];
                $catalog = $this->catalogIdentifiers->resolve($product, $variant);
                if (! $catalog->mapped()) {
                    Log::warning('meta_catalog_mapping_missing', ['product_public_id' => $product->public_id, 'event_type' => 'Purchase', 'mapping_state' => 'missing']);
                }
                $lines[] = compact('product', 'variant', 'regular', 'effective', 'item', 'catalog');
            }
            if (isset($data['promo_code']) && trim((string) $data['promo_code']) !== '' && ! $this->settings->get('checkout.promo_field_visible')) {
                throw new PromoCodeUnavailable;
            }
            $promotion = isset($data['promo_code']) && trim((string) $data['promo_code']) !== '' ? $this->promoCodes->quote((string) $data['promo_code'], $subtotal, true) : null;
            $promoDiscount = $promotion['discount_millimes'] ?? 0;
            $discountedMerchandiseSubtotal = $subtotal - $promoDiscount;
            $shipping = $this->shippingCalculator->calculate($discountedMerchandiseSubtotal);
            $order = Order::query()->create(['checkout_idempotency_key' => $idempotencyKey, 'checkout_payload_hash' => $payloadHash, 'status' => 'nouvelle', 'customer_name' => $resolved['customer']['full_name'], 'customer_phone' => $resolved['customer']['phone'], 'customer_city' => $resolved['customer']['city'], 'customer_governorate' => $resolved['customer']['governorate'], 'customer_address' => $resolved['customer']['address'], ...$exchange, 'subtotal_millimes' => $subtotal, 'product_discount_millimes' => $discount, 'promo_code_discount_millimes' => $promoDiscount, 'shipping_fee_millimes' => $shipping['fee']['millimes'], 'total_millimes' => $discountedMerchandiseSubtotal + $shipping['fee']['millimes'], 'promo_code_id' => $promotion['model']->id ?? null, 'promo_code_snapshot' => $promotion === null ? null : ['code' => $promotion['code'], 'discount_percentage' => $promotion['percentage']]]);
            $this->customers->recordOrder($order, $resolved['customer']);
            foreach ($lines as $line) {
                $product = $line['product'];
                $variant = $line['variant'];
                $quantity = $line['item']['quantity'];
                $order->items()->create(['product_id' => $product->id, 'product_variant_id' => $variant?->id, 'meta_catalog_id_snapshot' => $line['catalog']->identifier, 'product_name_snapshot' => $product->name, 'variant_snapshot' => $variant ? $variant->values->map(fn ($value) => ['group' => $value->productOptionGroup?->name, 'value' => $value->value])->all() : null, 'regular_unit_price_millimes' => $line['regular'], 'effective_unit_price_millimes' => $line['effective'], 'quantity' => $quantity, 'line_total_millimes' => $line['effective'] * $quantity]);
                $before = $variant ? $variant->stock_quantity : $product->stock_quantity;
                $target = $variant ?? $product;
                $target->decrement('stock_quantity', $quantity);
                InventoryMovement::query()->create(['product_id' => $product->id, 'product_variant_id' => $variant?->id, 'type' => 'order_deduction', 'quantity_delta' => -$quantity, 'quantity_before' => $before, 'quantity_after' => $before - $quantity, 'reason' => 'Commande '.$order->public_reference]);
            }
            foreach ($resolved['checkout_values'] as $value) {
                $order->checkoutValues()->create($value);
            }
            if ($promotion !== null) {
                $this->promoCodes->consume($promotion['model']);
            }
            DB::table('order_status_history')->insert(['order_id' => $order->id, 'from_status' => null, 'to_status' => 'nouvelle', 'created_at' => now()]);
            CheckoutIdempotencyRecord::query()->create(['order_id' => $order->id, 'idempotency_key' => $idempotencyKey, 'canonical_payload_hash' => $payloadHash, 'expires_at' => now()->addDays(7)]);
            if ($draft !== null) {
                $this->drafts->markConverted($draft, $order->id);
                DB::table('order_status_history')->insert([
                    'order_id' => $order->id,
                    'from_status' => null,
                    'to_status' => 'brouillon',
                    'reason' => 'Panier abandonné récupéré par le client.',
                    'changed_by' => null,
                    'created_at' => $order->created_at?->copy()->subSecond() ?? now()->subSecond(),
                ]);
            }

            $purchaseIneligibility = $this->metaPurchaseEligibility->ineligibilityReason($order, $this->request);
            if ($purchaseIneligibility === null) {
                $order->load('items');
                $purchaseItems = $order->items;
                $mappedPurchaseItems = $purchaseItems->filter(fn ($item): bool => is_string($item->meta_catalog_id_snapshot) && $item->meta_catalog_id_snapshot !== '');
                $event = $this->metaEvents->create(
                    'Purchase',
                    $this->marketingConsent->current($this->request)['policy_version'],
                    $this->request->fullUrl(),
                    [
                        'order_reference' => $order->public_reference,
                        'checkout_source' => $data['checkout_source'] ?? 'cart',
                        'content_ids' => $mappedPurchaseItems->pluck('meta_catalog_id_snapshot')->unique()->values()->all(),
                        'contents' => $mappedPurchaseItems->map(fn ($item): array => ['id' => $item->meta_catalog_id_snapshot, 'quantity' => $item->quantity, 'item_price_millimes' => $item->effective_unit_price_millimes])->values()->all(),
                        'catalog_mapping_state' => $mappedPurchaseItems->count() === $purchaseItems->count() ? 'complete' : ($mappedPurchaseItems->isNotEmpty() ? 'partial' : 'missing'),
                        'catalog_mapping_missing_count' => $purchaseItems->count() - $mappedPurchaseItems->count(),
                        'item_count' => $purchaseItems->sum('quantity'),
                        'value_millimes' => $order->total_millimes,
                        'currency' => 'TND',
                    ],
                    $order,
                    $resolved['customer']['phone'],
                    is_array($draft?->attribution_snapshot) ? $draft->attribution_snapshot : null,
                );
                if ($event) {
                    DB::afterCommit(fn () => DispatchMetaEventJob::dispatch($event->public_id)->onQueue('meta'));
                }
            } else {
                DB::afterCommit(fn () => Log::notice('meta_purchase_not_queued', [
                    'order_reference' => $order->public_reference,
                    'reason' => $purchaseIneligibility,
                ]));
            }

            return ['order' => $order->load('items', 'checkoutValues'), 'replayed' => false];
        }, 3);
    }

    /** @param array<int, array<string, mixed>> $fields */
    public function schemaVersion(array $fields): string
    {
        return $this->resolveCheckoutSubmissionAction->schemaVersion($fields);
    }

    /** @param array<string, mixed> $value
     * @return array<string, mixed>
     */
    private function canonicalize(array $value): array
    {
        ksort($value);
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonicalize($item);
            }
        }

        return $value;
    }
}
