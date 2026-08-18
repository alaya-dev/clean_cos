<?php

namespace App\Domain\Catalog\Actions;

use App\Domain\Audit\Actions\RecordAuditEventAction;
use App\Domain\Catalog\Models\InventoryMovement;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdjustInventoryAction
{
    public function __construct(private readonly RecordAuditEventAction $audit) {}

    public function handle(Product $product, ?string $variantPublicId, int $quantityDelta, string $reason, int $actorId): void
    {
        DB::transaction(function () use ($product, $variantPublicId, $quantityDelta, $reason, $actorId): void {
            $product = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $target = $variantPublicId ? ProductVariant::query()->where('product_id', $product->id)->where('is_current', true)->where('public_id', $variantPublicId)->lockForUpdate()->firstOrFail() : $product;
            if ($product->has_variants !== ($variantPublicId !== null)) {
                throw ValidationException::withMessages(['variant_public_id' => 'La cible de stock ne correspond pas au produit.']);
            }
            $before = $target->stock_quantity;
            if ($before === null || $before + $quantityDelta < 0) {
                throw ValidationException::withMessages(['quantity_delta' => 'Le stock ne peut pas devenir négatif.']);
            }
            $target->update(['stock_quantity' => $before + $quantityDelta]);
            InventoryMovement::query()->create(['product_id' => $product->id, 'product_variant_id' => $target instanceof ProductVariant ? $target->id : null, 'actor_user_id' => $actorId, 'type' => 'manual_adjustment', 'quantity_delta' => $quantityDelta, 'quantity_before' => $before, 'quantity_after' => $before + $quantityDelta, 'reason' => $reason]);
            $this->audit->handle('inventory.adjusted', $product, User::query()->find($actorId), after: ['quantity_delta' => $quantityDelta, 'variant_public_id' => $variantPublicId]);
        });
    }
}
