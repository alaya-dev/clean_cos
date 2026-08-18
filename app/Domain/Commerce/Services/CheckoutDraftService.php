<?php

namespace App\Domain\Commerce\Services;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\CatalogPriceResolver;
use App\Domain\Commerce\Models\CheckoutDraft;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CheckoutDraftService
{
    public function __construct(private readonly CatalogPriceResolver $prices) {}

    /**
     * @param  array<string, mixed>  $customer
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<string, mixed>  $checkoutData
     * @param  array<string, mixed>|null  $attribution
     */
    public function upsert(?string $token, array $customer, array $items, array $checkoutData = [], ?string $promoCode = null, ?array $attribution = null): CheckoutDraft
    {
        return DB::transaction(function () use ($token, $customer, $items, $checkoutData, $promoCode, $attribution): CheckoutDraft {
            $draft = $token === null ? null : CheckoutDraft::query()->where('public_token', $token)->lockForUpdate()->first();
            $draft ??= new CheckoutDraft(['public_token' => (string) Str::uuid()]);
            if ($draft->converted_at !== null) {
                return $draft;
            }
            $draft->fill([
                'customer_data' => $this->customerData($customer),
                'cart_snapshot' => $this->cartSnapshot($items),
                'checkout_data' => $this->limitData($checkoutData),
                // Keep an eligible first-party attribution snapshot when a later
                // autosave has no new consent-bearing context to contribute.
                'attribution_snapshot' => $attribution ?? $draft->attribution_snapshot,
                'promo_code' => $promoCode === null ? null : mb_substr(trim($promoCode), 0, 80),
                'last_activity_at' => now(),
                'converted_at' => $draft->converted_at,
                'order_id' => $draft->order_id,
            ])->save();

            return $draft->fresh() ?? $draft;
        });
    }

    public function findForUpdate(string $token): ?CheckoutDraft
    {
        return CheckoutDraft::query()->where('public_token', $token)->lockForUpdate()->first();
    }

    public function markConverted(CheckoutDraft $draft, int $orderId): void
    {
        $draft->forceFill(['converted_at' => now(), 'order_id' => $orderId])->save();
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function cartSnapshot(array $items): array
    {
        $publicIds = collect($items)->pluck('product_public_id')->filter()->unique()->values()->all();
        $products = Product::query()->with(['variants.values.productOptionGroup'])->whereIn('public_id', $publicIds)->get()->keyBy('public_id');

        return collect($items)->map(function (array $item) use ($products): ?array {
            $product = $products->get($item['product_public_id'] ?? null);
            if (! $product) {
                return null;
            }
            $variant = $product->has_variants ? $product->variants->firstWhere('public_id', $item['variant_public_id'] ?? null) : null;
            $price = $this->prices->resolve($product, $variant);

            return [
                'product_public_id' => $product->public_id,
                'variant_public_id' => $variant?->public_id,
                'name' => $product->name,
                'variant_label' => $variant?->values->map(fn ($value): string => (string) $value->value)->implode(' · '),
                'quantity' => max(1, min(99, (int) ($item['quantity'] ?? 1))),
                'regular_price_millimes' => $price['regular_millimes'],
                'effective_price_millimes' => $price['effective_millimes'],
            ];
        })->filter()->values()->all();
    }

    /**
     * @param  array<string, mixed>  $customer
     * @return array<string, string>
     */
    private function customerData(array $customer): array
    {
        return collect($customer)->only(['full_name', 'phone', 'governorate', 'city', 'address'])->map(fn (mixed $value): string => is_scalar($value) ? mb_substr(trim((string) $value), 0, 2000) : '')->all();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function limitData(array $data): array
    {
        return collect($data)->map(fn (mixed $value): mixed => is_scalar($value) ? mb_substr((string) $value, 0, 500) : null)->all();
    }
}
