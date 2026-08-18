<?php

namespace App\Domain\MetaTracking\Services;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;

class MetaCatalogIdentifierResolver
{
    public function resolve(Product $product, ?ProductVariant $variant = null): MetaCatalogIdentifierResolution
    {
        $identifier = $product->meta_catalog_id;

        return new MetaCatalogIdentifierResolution(
            is_string($identifier) && trim($identifier) !== '' ? trim($identifier) : null,
            'product',
        );
    }
}
