<?php

namespace App\Domain\Catalog\Actions;

use App\Domain\Audit\Actions\RecordAuditEventAction;
use App\Domain\Catalog\Models\InventoryMovement;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class BulkAdjustInventoryAction
{
    public function __construct(private readonly RecordAuditEventAction $audit) {}

    /**
     * A product-level selection expands to every current stock-bearing variant.
     * An explicit variant target remains supported for existing API consumers.
     *
     * @param  array<int, array{product_public_id:string, variant_public_id?:string|null}>  $items
     * @return array{products_updated:int, stock_records_updated:int, variant_records_updated:int}
     */
    public function handle(array $items, string $operation, int $quantity, User $actor): array
    {
        if ($quantity < 0) {
            throw ValidationException::withMessages(['quantity' => 'La quantité doit être positive ou nulle.']);
        }

        return DB::transaction(function () use ($items, $operation, $quantity, $actor): array {
            $productsUpdated = 0;
            $stockRecordsUpdated = 0;
            $variantRecordsUpdated = 0;
            $firstProduct = null;
            $targets = [];
            foreach ($items as $item) {
                $product = Product::query()->where('public_id', $item['product_public_id'])->lockForUpdate()->firstOrFail();
                $firstProduct ??= $product;
                $variantPublicId = $item['variant_public_id'] ?? null;
                if ($variantPublicId !== null) {
                    $variants = ProductVariant::query()
                        ->where('product_id', $product->id)
                        ->where('is_current', true)
                        ->where('public_id', $variantPublicId)
                        ->lockForUpdate()
                        ->get();
                    if (! $product->has_variants || $variants->isEmpty()) {
                        throw ValidationException::withMessages(['items' => 'Chaque cible doit correspondre à une variante actuelle du produit.']);
                    }
                    $targets[] = [$product, $variants->first()];
                } elseif ($product->has_variants) {
                    $variants = ProductVariant::query()
                        ->where('product_id', $product->id)
                        ->where('is_current', true)
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get();
                    if ($variants->isEmpty()) {
                        throw ValidationException::withMessages(['items' => 'Ce produit ne possède aucune variante actuelle à modifier.']);
                    }
                    foreach ($variants as $variant) {
                        $targets[] = [$product, $variant];
                    }
                } else {
                    $targets[] = [$product, $product];
                }
            }

            foreach ($targets as [$product, $target]) {
                $before = $target->stock_quantity;
                if ($before === null) {
                    throw ValidationException::withMessages(['items' => 'Une ligne sélectionnée ne possède pas de stock modifiable.']);
                }
                $after = match ($operation) {
                    'set' => $quantity,
                    'increase' => $before + $quantity,
                    'decrease' => $before - $quantity,
                    default => throw ValidationException::withMessages(['operation' => 'Cette opération de stock est invalide.']),
                };
                if ($after < 0) {
                    throw ValidationException::withMessages(['quantity' => 'La diminution demandée rendrait le stock négatif.']);
                }
            }

            foreach ($targets as [$product, $target]) {
                $before = $target->stock_quantity;
                $after = match ($operation) {
                    'set' => $quantity,
                    'increase' => $before + $quantity,
                    'decrease' => $before - $quantity,
                };
                $target->update(['stock_quantity' => $after]);
                InventoryMovement::query()->create([
                    'product_id' => $product->id,
                    'product_variant_id' => $target instanceof ProductVariant ? $target->id : null,
                    'actor_user_id' => $actor->id,
                    'type' => 'manual_adjustment',
                    'quantity_delta' => $after - $before,
                    'quantity_before' => $before,
                    'quantity_after' => $after,
                    'reason' => 'Gestion groupée : '.$operation,
                ]);
                $stockRecordsUpdated++;
                $variantRecordsUpdated += $target instanceof ProductVariant ? 1 : 0;
            }
            $productsUpdated = count($items);
            if ($firstProduct !== null) {
                $this->audit->handle('inventory.bulk_adjusted', $firstProduct, $actor, after: ['operation' => $operation, 'quantity' => $quantity, 'products_count' => $productsUpdated, 'stock_records_count' => $stockRecordsUpdated, 'variant_records_count' => $variantRecordsUpdated]);
            }

            return ['products_updated' => $productsUpdated, 'stock_records_updated' => $stockRecordsUpdated, 'variant_records_updated' => $variantRecordsUpdated];
        });
    }
}
