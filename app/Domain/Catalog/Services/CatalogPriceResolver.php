<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;

final class CatalogPriceResolver
{
    /**
     * Resolve the customer-facing price from the canonical catalog fields.
     *
     * A promotion is only valid when it is strictly lower than the applicable
     * regular price. This defensive check also protects order flows from
     * historical catalog data that predates the editor validation rule.
     *
     * @return array{regular_millimes: int, effective_millimes: int}
     */
    public function resolve(Product $product, ?ProductVariant $variant = null): array
    {
        $variantRegular = $variant?->regular_price_millimes;
        $regular = max(0, (int) ($variantRegular ?? $product->regular_price_millimes));
        $promotional = $variant?->promotional_price_millimes;

        if ($promotional === null && $variantRegular === null) {
            $promotional = $product->promotional_price_millimes;
        }

        $effective = $promotional !== null && $promotional < $regular
            ? (int) $promotional
            : $regular;

        return [
            'regular_millimes' => $regular,
            'effective_millimes' => $effective,
        ];
    }
}
