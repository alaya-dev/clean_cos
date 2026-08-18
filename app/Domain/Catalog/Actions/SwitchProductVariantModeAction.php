<?php

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SwitchProductVariantModeAction
{
    public function __construct(
        private readonly DeactivateProductVariantsForSimpleProductAction $deactivateVariants,
    ) {}

    public function handle(Product $product, bool $hasVariants, ?int $resultingStockQuantity = null): Product
    {
        if ($product->has_variants === $hasVariants) {
            return $product;
        }
        if ($hasVariants) {
            if ($product->stock_quantity === null) {
                throw ValidationException::withMessages(['stock_quantity' => 'Le stock produit est requis avant d’activer les variantes.']);
            }

            return DB::transaction(function () use ($product): Product {
                $product->update(['has_variants' => true, 'stock_quantity' => null, 'low_stock_threshold' => null, 'lock_version' => $product->lock_version + 1]);

                return $product->refresh();
            });
        }
        if ($resultingStockQuantity === null || $resultingStockQuantity < 0) {
            throw ValidationException::withMessages(['resulting_stock_quantity' => 'Le stock final est requis.']);
        }

        return DB::transaction(function () use ($product, $resultingStockQuantity): Product {
            $this->deactivateVariants->handle($product);
            $product->update(['has_variants' => false, 'stock_quantity' => $resultingStockQuantity, 'low_stock_threshold' => null, 'lock_version' => $product->lock_version + 1]);

            return $product->refresh();
        });
    }
}
