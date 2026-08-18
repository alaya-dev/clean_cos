<?php

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateProductAction
{
    /** @param array<string, mixed> $data */
    public function handle(array $data): Product
    {
        return DB::transaction(function () use ($data): Product {
            $category = Category::query()->where('public_id', $data['category_public_id'])->firstOrFail();
            $hasVariants = (bool) ($data['has_variants'] ?? false);
            $this->validateStockMode($data, $hasVariants);
            $sortOrder = ((int) ($category->products()->max('sort_order') ?? -1)) + 1;
            $product = Product::query()->create([
                'category_id' => $category->id, 'sort_order' => $sortOrder, 'name' => $data['name'], 'slug' => $data['slug'], 'meta_catalog_id' => $data['meta_catalog_id'] ?? null, 'short_description' => $data['short_description'] ?? null,
                'full_description' => $data['full_description'] ?? null, 'regular_price_millimes' => $data['regular_price_millimes'], 'promotional_price_millimes' => $data['promotional_price_millimes'] ?? null,
                'stock_quantity' => $hasVariants ? null : $data['stock_quantity'], 'low_stock_threshold' => $hasVariants ? null : ($data['low_stock_threshold'] ?? null),
                'is_active' => (bool) ($data['is_active'] ?? false), 'has_variants' => $hasVariants, 'published_at' => $data['published_at'] ?? null,
                'seo_title' => $data['seo_title'] ?? null, 'seo_description' => $data['seo_description'] ?? null,
            ]);
            if ($hasVariants) {
                $this->replaceVariants($product, $data['option_groups'] ?? [], $data['variants'] ?? []);
            }

            $product->refresh()->load(['category', 'optionGroups.values.parentValue', 'variants.values.parentValue']);

            return $product;
        });
    }

    /** @param array<string, mixed> $data */
    private function validateStockMode(array $data, bool $hasVariants): void
    {
        if (($data['promotional_price_millimes'] ?? null) !== null && $data['promotional_price_millimes'] >= $data['regular_price_millimes']) {
            throw ValidationException::withMessages(['promotional_price_millimes' => 'Le prix promotionnel doit être inférieur au prix normal.']);
        }
        if (! $hasVariants && ! isset($data['stock_quantity'])) {
            throw ValidationException::withMessages(['stock_quantity' => 'Le stock produit est requis sans variantes.']);
        }
        if ($hasVariants && ! empty($data['stock_quantity'])) {
            throw ValidationException::withMessages(['stock_quantity' => 'Le stock produit doit être vide avec variantes.']);
        }
    }

    /**
     * @param  array<int, array{name: string, sort_order?: int, values: array<int, array{client_key: string, value: string, parent_client_key?: string|null, sort_order?: int}>}>  $groups
     * @param  array<int, array{option_value_client_keys: array<int, string>, stock_quantity: int, sku?: string|null, regular_price_millimes?: int|null, promotional_price_millimes?: int|null, low_stock_threshold?: int|null, is_active?: bool, is_default?: bool}>  $variants
     */
    private function replaceVariants(Product $product, array $groups, array $variants): void
    {
        if ($variants === []) {
            throw ValidationException::withMessages(['variants' => 'Au moins une variante est requise.']);
        }
        $values = [];
        foreach ($groups as $groupIndex => $groupData) {
            $group = $product->optionGroups()->create(['name' => $groupData['name'], 'sort_order' => $groupData['sort_order'] ?? 0]);
            foreach ($groupData['values'] as $valueData) {
                $parentKey = $valueData['parent_client_key'] ?? null;
                $parent = $parentKey === null ? null : ($values[$parentKey] ?? null);
                if ($parentKey !== null && $parent === null) {
                    throw ValidationException::withMessages(['option_groups' => 'Une valeur dépendante doit viser une valeur parente définie dans un niveau précédent.']);
                }
                if ($parent !== null && $groupIndex === 0) {
                    throw ValidationException::withMessages(['option_groups' => 'Le premier niveau ne peut pas dépendre d’une autre valeur.']);
                }
                $values[$valueData['client_key']] = $group->values()->create(['value' => $valueData['value'], 'parent_product_option_value_id' => $parent?->id, 'sort_order' => $valueData['sort_order'] ?? 0]);
            }
        }
        $seen = [];
        foreach ($variants as $variantData) {
            $ids = [];
            foreach ($variantData['option_value_client_keys'] as $optionValueKey) {
                if (! isset($values[$optionValueKey])) {
                    throw ValidationException::withMessages(['variants' => 'Combinaison de variante invalide.']);
                }
                $ids[] = $values[$optionValueKey]->id;
            }
            sort($ids);
            if (count($ids) !== count($groups)) {
                throw ValidationException::withMessages(['variants' => 'Combinaison de variante invalide.']);
            }
            foreach ($variantData['option_value_client_keys'] as $optionValueKey) {
                $value = $values[$optionValueKey];
                if ($value->parent_product_option_value_id !== null && ! in_array($value->parent_product_option_value_id, $ids, true)) {
                    throw ValidationException::withMessages(['variants' => 'Une combinaison doit respecter les dépendances entre options.']);
                }
            }
            $key = implode(':', $ids);
            if (isset($seen[$key])) {
                throw ValidationException::withMessages(['variants' => 'Combinaison de variante dupliquée.']);
            } $seen[$key] = true;
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
    }
}
