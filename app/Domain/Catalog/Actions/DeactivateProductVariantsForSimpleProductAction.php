<?php

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Models\Product;

class DeactivateProductVariantsForSimpleProductAction
{
    /**
     * Keeps historical variant and inventory references intact while making a
     * product sellable as a simple product again. The caller owns the transaction.
     */
    public function handle(Product $product): void
    {
        $product->images()
            ->whereNotNull('product_variant_id')
            ->update(['product_variant_id' => null]);

        $product->allVariants()
            ->where('is_current', true)
            ->update(['is_active' => false, 'is_default' => false, 'is_current' => false]);

        $product->allOptionGroups()
            ->where('is_current', true)
            ->update(['is_current' => false]);
    }
}
