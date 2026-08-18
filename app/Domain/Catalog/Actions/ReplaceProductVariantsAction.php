<?php

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReplaceProductVariantsAction
{
    /**
     * @param  array<int, array{name: string, sort_order?: int, values: array<int, array{client_key: string, value: string, parent_client_key?: string|null, sort_order?: int}>}>  $groups
     * @param  array<int, array{option_value_client_keys: array<int, string>, stock_quantity: int, sku?: string|null, regular_price_millimes?: int|null, promotional_price_millimes?: int|null, low_stock_threshold?: int|null, is_active?: bool, is_default?: bool}>  $variants
     */
    public function handle(Product $product, array $groups, array $variants, int $lockVersion): Product
    {
        if (! $product->has_variants) {
            throw ValidationException::withMessages(['has_variants' => 'Ce produit n’utilise pas de variantes.']);
        }
        if ($product->lock_version !== $lockVersion) {
            throw ValidationException::withMessages(['lock_version' => 'Le produit a été modifié par un autre utilisateur.']);
        }
        if ($variants === []) {
            throw ValidationException::withMessages(['variants' => 'Au moins une variante est requise.']);
        }

        return DB::transaction(function () use ($product, $groups, $variants): Product {
            $this->archiveCurrentStructure($product);
            $valueMap = [];
            foreach ($groups as $groupIndex => $groupData) {
                $group = $product->optionGroups()->create(['name' => $groupData['name'], 'sort_order' => $groupData['sort_order'] ?? 0]);
                foreach ($groupData['values'] as $valueData) {
                    $parentKey = $valueData['parent_client_key'] ?? null;
                    $parent = $parentKey === null ? null : ($valueMap[$parentKey] ?? null);
                    if ($parentKey !== null && $parent === null) {
                        throw ValidationException::withMessages(['option_groups' => 'Une valeur dépendante doit viser une valeur parente définie dans un niveau précédent.']);
                    }
                    if ($parent !== null && $groupIndex === 0) {
                        throw ValidationException::withMessages(['option_groups' => 'Le premier niveau ne peut pas dépendre d’une autre valeur.']);
                    }
                    $valueMap[$valueData['client_key']] = $group->values()->create(['value' => $valueData['value'], 'parent_product_option_value_id' => $parent?->id, 'sort_order' => $valueData['sort_order'] ?? 0]);
                }
            }
            $seen = [];
            foreach ($variants as $variantData) {
                $ids = [];
                foreach ($variantData['option_value_client_keys'] as $optionValueKey) {
                    if (! isset($valueMap[$optionValueKey])) {
                        throw ValidationException::withMessages(['variants' => 'Combinaison de variante invalide.']);
                    }
                    $ids[] = $valueMap[$optionValueKey]->id;
                }
                sort($ids);
                if (count($ids) !== count($groups)) {
                    throw ValidationException::withMessages(['variants' => 'Combinaison de variante invalide.']);
                }
                foreach ($variantData['option_value_client_keys'] as $optionValueKey) {
                    $value = $valueMap[$optionValueKey];
                    if ($value->parent_product_option_value_id !== null && ! in_array($value->parent_product_option_value_id, $ids, true)) {
                        throw ValidationException::withMessages(['variants' => 'Une combinaison doit respecter les dépendances entre options.']);
                    }
                }
                $key = implode(':', $ids);
                if (isset($seen[$key])) {
                    throw ValidationException::withMessages(['variants' => 'Combinaison de variante dupliquée.']);
                }
                $seen[$key] = true;
                $regular = $variantData['regular_price_millimes'] ?? null;
                $promotional = $variantData['promotional_price_millimes'] ?? null;
                if ($promotional !== null && $regular !== null && $promotional >= $regular) {
                    throw ValidationException::withMessages(['variants' => 'Le prix promotionnel d’une variante doit être inférieur à son prix normal.']);
                }
                $variant = $product->variants()->create(['sku' => $variantData['sku'] ?? null, 'combination_key' => $key, 'regular_price_millimes' => $regular, 'promotional_price_millimes' => $promotional, 'stock_quantity' => $variantData['stock_quantity'], 'low_stock_threshold' => $variantData['low_stock_threshold'] ?? null, 'is_active' => $variantData['is_active'] ?? true, 'is_default' => $variantData['is_default'] ?? false]);
                $variant->values()->sync($ids);
            }
            $default = $product->variants()->where('is_default', true)->orderBy('id')->first();
            if ($default === null) {
                $product->variants()->where('is_active', true)->orderBy('id')->first()?->update(['is_default' => true]);
            } elseif ($product->variants()->where('is_default', true)->count() > 1) {
                $product->variants()->where('is_default', true)->whereKeyNot($default->id)->update(['is_default' => false]);
            }
            $product->increment('lock_version');

            $product->load(['optionGroups.values', 'variants.values']);

            return $product;
        });
    }

    private function archiveCurrentStructure(Product $product): void
    {
        $product->images()
            ->whereNotNull('product_variant_id')
            ->update(['product_variant_id' => null]);

        $product->allVariants()
            ->where('is_current', true)
            ->update([
                'is_active' => false,
                'is_default' => false,
                'is_current' => false,
            ]);

        $product->allOptionGroups()
            ->where('is_current', true)
            ->update(['is_current' => false]);
    }
}
