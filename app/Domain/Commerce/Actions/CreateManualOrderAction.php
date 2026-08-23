<?php

namespace App\Domain\Commerce\Actions;

use App\Domain\Catalog\Models\InventoryMovement;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\CatalogPriceResolver;
use App\Domain\Checkout\Actions\ResolveCheckoutSubmissionAction;
use App\Domain\Checkout\Services\ShippingCalculator;
use App\Domain\Commerce\Exceptions\CheckoutConflictException;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Services\CustomerHistoryService;
use App\Domain\Commerce\Services\OrderExchangeDetails;
use App\Domain\Commerce\Support\OrderStatusFlow;
use App\Domain\FirstDelivery\Services\FirstDeliveryShipmentService;
use App\Domain\MetaTracking\Services\MetaCatalogIdentifierResolver;
use App\Domain\MetaTracking\Services\MetaEventFactory;
use App\Domain\Navex\Services\NavexShipmentService;
use App\Jobs\DispatchMetaEventJob;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Creates an operator-entered order using the same authoritative commerce rules as checkout. */
class CreateManualOrderAction
{
    public function __construct(
        private readonly ResolveCheckoutSubmissionAction $checkout,
        private readonly ShippingCalculator $shipping,
        private readonly CatalogPriceResolver $prices,
        private readonly MetaCatalogIdentifierResolver $catalogIdentifiers,
        private readonly MetaEventFactory $metaEvents,
        private readonly NavexShipmentService $navex,
        private readonly FirstDeliveryShipmentService $firstDeliveryShipments,
        private readonly OrderExchangeDetails $exchangeDetails,
        private readonly CustomerHistoryService $customers,
    ) {}

    /** @param array<string, mixed> $data */
    public function handle(array $data, User $actor): Order
    {
        return DB::transaction(function () use ($data, $actor): Order {
            $idempotencyKey = (string) $data['idempotency_key'];
            $payload = $data;
            unset($payload['idempotency_key']);
            $payloadHash = hash('sha256', json_encode($this->canonicalize($payload), JSON_THROW_ON_ERROR));
            $existing = Order::query()->where('checkout_idempotency_key', $idempotencyKey)->first();
            if ($existing !== null) {
                if (! hash_equals((string) $existing->checkout_payload_hash, $payloadHash)) {
                    throw new CheckoutConflictException('MANUAL_ORDER_IDEMPOTENCY_CONFLICT', 'Cette demande ne correspond pas à la précédente tentative.');
                }

                return $existing->load('items', 'checkoutValues');
            }
            $checkoutData = $data;
            $firstDeliveryLocalityId = $checkoutData['customer']['first_delivery_locality_id'] ?? null;
            unset($checkoutData['customer']['first_delivery_locality_id']);
            $resolved = $this->checkout->handle($checkoutData);
            $exchange = $this->exchangeDetails->normalize($data['exchange'] ?? null);
            $productIds = array_values(array_unique(array_column($data['items'], 'product_public_id')));
            sort($productIds);
            $products = Product::query()
                ->with(['category:id,is_active', 'variants.values.productOptionGroup'])
                ->whereIn('public_id', $productIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('public_id');

            $lines = [];
            $subtotal = 0;
            $discount = 0;
            foreach ($data['items'] as $item) {
                /** @var Product|null $product */
                $product = $products->get($item['product_public_id']);
                if (! $product || ! $product->is_active || ! $product->category?->is_active) {
                    throw new CheckoutConflictException('PRODUCT_UNAVAILABLE', 'Un produit de cette commande n’est plus disponible.');
                }
                $variant = null;
                if ($product->has_variants) {
                    $variant = $product->variants->firstWhere('public_id', $item['variant_public_id']);
                    if (! $variant || ! $variant->is_active) {
                        throw new CheckoutConflictException('VARIANT_UNAVAILABLE', 'Une variante de cette commande n’est plus disponible.');
                    }
                    if ((int) $variant->stock_quantity < (int) $item['quantity']) {
                        throw new CheckoutConflictException('INSUFFICIENT_STOCK', 'Le stock disponible a changé.');
                    }
                } elseif ($item['variant_public_id'] || (int) ($product->stock_quantity ?? 0) < (int) $item['quantity']) {
                    throw new CheckoutConflictException('INSUFFICIENT_STOCK', 'Le stock disponible a changé.');
                }

                $price = $this->prices->resolve($product, $variant);
                $regular = $price['regular_millimes'];
                $effective = $price['effective_millimes'];
                $subtotal += $effective * (int) $item['quantity'];
                $discount += ($regular - $effective) * (int) $item['quantity'];
                $lines[] = compact('product', 'variant', 'regular', 'effective', 'item');
            }

            // A telephone/Messenger order has no promo-code field. Product-level
            // promotions remain part of the authoritative catalog price above.
            $promoDiscount = 0;
            $shipping = $this->shipping->calculate($subtotal);
            $manualTotal = array_key_exists('manual_total_millimes', $data) && $data['manual_total_millimes'] !== null
                ? (int) $data['manual_total_millimes']
                : null;
            $status = (string) ($data['status'] ?? 'nouvelle');
            if (! in_array($status, array_diff(OrderStatusFlow::STATUSES, ['annulee']), true)) {
                throw ValidationException::withMessages(['status' => 'Le statut initial doit représenter une commande active.']);
            }

            $order = Order::query()->create([
                // Manual entries use an operator-form idempotency key and never
                // share one with a customer checkout.
                'checkout_idempotency_key' => $idempotencyKey,
                'checkout_payload_hash' => $payloadHash,
                'status' => $status,
                'customer_name' => $resolved['customer']['full_name'],
                'customer_phone' => $resolved['customer']['phone'],
                'customer_city' => $resolved['customer']['city'],
                'customer_governorate' => $resolved['customer']['governorate'],
                'first_delivery_locality_id' => $firstDeliveryLocalityId === null ? null : (int) $firstDeliveryLocalityId,
                'customer_address' => $resolved['customer']['address'],
                ...$exchange,
                'subtotal_millimes' => $subtotal,
                'product_discount_millimes' => $discount,
                'promo_code_discount_millimes' => $promoDiscount,
                'shipping_fee_millimes' => $shipping['fee']['millimes'],
                'total_millimes' => $manualTotal ?? ($subtotal + $shipping['fee']['millimes']),
                'manual_total_millimes' => $manualTotal,
                'promo_code_id' => null,
                'promo_code_snapshot' => null,
            ]);
            $this->customers->recordOrder($order, $resolved['customer']);

            foreach ($lines as $line) {
                /** @var Product $product */
                $product = $line['product'];
                $variant = $line['variant'];
                $quantity = (int) $line['item']['quantity'];
                $catalog = $this->catalogIdentifiers->resolve($product, $variant);
                $order->items()->create([
                    'product_id' => $product->id,
                    'product_variant_id' => $variant?->id,
                    'meta_catalog_id_snapshot' => $catalog->identifier,
                    'product_name_snapshot' => $product->name,
                    'variant_snapshot' => $variant ? $variant->values->map(fn ($value): array => ['group' => $value->productOptionGroup?->name, 'value' => $value->value])->all() : null,
                    'regular_unit_price_millimes' => $line['regular'],
                    'effective_unit_price_millimes' => $line['effective'],
                    'quantity' => $quantity,
                    'line_total_millimes' => $line['effective'] * $quantity,
                ]);
                $target = $variant ?? $product;
                $before = (int) $target->stock_quantity;
                $target->decrement('stock_quantity', $quantity);
                InventoryMovement::query()->create([
                    'product_id' => $product->id,
                    'product_variant_id' => $variant?->id,
                    'actor_user_id' => $actor->id,
                    'type' => 'order_deduction',
                    'quantity_delta' => -$quantity,
                    'quantity_before' => $before,
                    'quantity_after' => $before - $quantity,
                    'reason' => 'Commande manuelle '.$order->public_reference,
                ]);
            }
            foreach ($resolved['checkout_values'] as $value) {
                $order->checkoutValues()->create($value);
            }
            DB::table('order_status_history')->insert([
                'order_id' => $order->id,
                'from_status' => null,
                'to_status' => $status,
                'reason' => 'Commande saisie manuellement.',
                'changed_by' => $actor->id,
                'created_at' => now(),
            ]);

            $order->load('items');
            $mappedItems = $order->items->filter(fn ($item): bool => filled($item->meta_catalog_id_snapshot));
            $event = $this->metaEvents->createManualPurchase((int) config('meta.consent_policy_version'), [
                'order_reference' => $order->public_reference,
                'checkout_source' => 'manual',
                'manual_order_source' => 'admin',
                'action_source' => 'website',
                'content_ids' => $mappedItems->pluck('meta_catalog_id_snapshot')->unique()->values()->all(),
                'contents' => $mappedItems->map(fn ($item): array => ['id' => $item->meta_catalog_id_snapshot, 'quantity' => $item->quantity, 'item_price_millimes' => $item->effective_unit_price_millimes])->values()->all(),
                'item_count' => $order->items->sum('quantity'),
                'value_millimes' => $order->total_millimes,
                'currency' => 'TND',
            ], $order, $resolved['customer']['phone'], is_array($data['meta_attribution'] ?? null) ? $data['meta_attribution'] : null);
            if ($event !== null) {
                DB::afterCommit(fn () => DispatchMetaEventJob::dispatch($event->public_id)->onQueue('meta'));
            }

            if ($status === 'confirmee') {
                try {
                    // Match the normal status-transition path. This records the
                    // durable shipment inside the order transaction; its HTTP job
                    // is dispatched only after the outer transaction commits.
                    $this->navex->queue($order, 'automatic');
                } catch (ValidationException) {
                    // A disabled/incomplete Navex integration must not undo an order.
                }
                try {
                    // Keep manual creation identical to the normal confirmation
                    // path. The shipment is persisted here, while its HTTP job is
                    // dispatched by FirstDeliveryShipmentService after commit.
                    $this->firstDeliveryShipments->queue($order, 'automatic');
                } catch (ValidationException) {
                    // A disabled/incomplete First Delivery integration must not undo an order.
                }
            }

            return $order->load('items', 'checkoutValues');
        }, 3);
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
