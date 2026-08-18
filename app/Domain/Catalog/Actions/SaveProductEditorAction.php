<?php

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductImage;
use App\Domain\Catalog\Models\ProductVariant;
use App\Jobs\DeleteProductImageFiles;
use App\Jobs\ProcessProductImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class SaveProductEditorAction
{
    public function __construct(
        private readonly CreateProductAction $createProduct,
        private readonly ReplaceProductVariantsAction $replaceVariants,
        private readonly DeactivateProductVariantsForSimpleProductAction $deactivateVariants,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, UploadedFile>  $uploads
     */
    public function handle(?Product $existingProduct, array $data, array $uploads): Product
    {
        $stagedUploads = $this->stageUploads($uploads);

        try {
            return DB::transaction(function () use ($existingProduct, $data, $stagedUploads): Product {
                $product = $existingProduct
                    ? $this->updateProduct($existingProduct, $data)
                    : $this->createProduct->handle($data);

                $variants = $product->has_variants
                    ? $product->variants()->orderBy('id')->get()
                    : collect();
                if (is_array($data['gallery'] ?? null)) {
                    $this->syncGallery($product, $data['gallery'], $stagedUploads, $variants);
                }

                return $product->refresh()->load(['category', 'images.variant', 'optionGroups.values.parentValue', 'variants.values.parentValue']);
            });
        } catch (Throwable $exception) {
            Storage::disk('local')->delete(array_values($stagedUploads));

            throw $exception;
        }
    }

    /** @param array<string, UploadedFile> $uploads
     * @return array<string, string>
     */
    private function stageUploads(array $uploads): array
    {
        $stagedUploads = [];
        try {
            foreach ($uploads as $key => $upload) {
                $path = $upload->store('product-staging', 'local');
                if ($path === false) {
                    throw ValidationException::withMessages([
                        'uploads' => 'Une image n’a pas pu être préparée pour l’enregistrement.',
                    ]);
                }
                $stagedUploads[$key] = $path;
            }
        } catch (Throwable $exception) {
            Storage::disk('local')->delete(array_values($stagedUploads));

            throw $exception;
        }

        return $stagedUploads;
    }

    /** @param array<string, mixed> $data */
    private function updateProduct(Product $existingProduct, array $data): Product
    {
        $product = Product::query()->whereKey($existingProduct->id)->lockForUpdate()->firstOrFail();
        if (($data['lock_version'] ?? null) !== $product->lock_version) {
            throw ValidationException::withMessages([
                'lock_version' => 'Le produit a été modifié par un autre utilisateur. Rechargez-le avant de l’enregistrer.',
            ]);
        }
        $category = Category::query()->where('public_id', $data['category_public_id'])->firstOrFail();
        $sortOrder = $category->id === $product->category_id
            ? $product->sort_order
            : ((int) ($category->products()->max('sort_order') ?? -1)) + 1;
        $hasVariants = (bool) $data['has_variants'];
        $preserveVariants = $hasVariants
            && $product->has_variants
            && $this->hasUnchangedVariantStructure($product, $data['option_groups'], $data['variants']);
        $this->validatePromotion($data);

        if ($product->has_variants && ! $hasVariants) {
            $this->deactivateVariants->handle($product);
        }

        $product->update([
            'category_id' => $category->id,
            'sort_order' => $sortOrder,
            'name' => $data['name'],
            'slug' => $data['slug'],
            'meta_catalog_id' => $data['meta_catalog_id'],
            'short_description' => $data['short_description'],
            'full_description' => $data['full_description'],
            'regular_price_millimes' => $data['regular_price_millimes'],
            'promotional_price_millimes' => $data['promotional_price_millimes'],
            'is_active' => $data['is_active'],
            'has_variants' => $hasVariants,
            'stock_quantity' => $hasVariants ? null : $data['stock_quantity'],
            'low_stock_threshold' => $hasVariants ? null : $data['low_stock_threshold'],
            'seo_title' => $data['seo_title'],
            'seo_description' => $data['seo_description'],
            'lock_version' => $product->lock_version + ($preserveVariants || ! $hasVariants || $product->has_variants !== $hasVariants ? 1 : 0),
        ]);

        if ($hasVariants) {
            $product->refresh();
            if ($preserveVariants) {
                $this->updateExistingVariants($product, $data['variants']);
            } else {
                $this->replaceVariants->handle($product, $data['option_groups'], $data['variants'], $product->lock_version);
            }
        }

        return $product->refresh();
    }

    /** @param array<string, mixed> $data */
    private function validatePromotion(array $data): void
    {
        if ($data['promotional_price_millimes'] !== null
            && $data['promotional_price_millimes'] >= $data['regular_price_millimes']) {
            throw ValidationException::withMessages([
                'promotional_price_millimes' => 'Le prix promotionnel doit être inférieur au prix normal.',
            ]);
        }
    }

    /**
     * @param  array<int, array{name: string, values: array<int, array{client_key: string, value: string, parent_client_key?: string|null}>}>  $groups
     * @param  array<int, array{public_id?: string|null, option_value_client_keys: array<int, string>}>  $variants
     */
    private function hasUnchangedVariantStructure(Product $product, array $groups, array $variants): bool
    {
        $product->loadMissing('optionGroups.values.parentValue', 'variants.values.parentValue');
        $currentGroups = $product->optionGroups->values();
        if (count($groups) !== $currentGroups->count() || count($variants) !== $product->variants->count()) {
            return false;
        }

        $clientKeysByValueId = [];
        foreach ($groups as $index => $groupData) {
            $currentGroup = $currentGroups->get($index);
            if ($currentGroup === null || $currentGroup->name !== $groupData['name']) {
                return false;
            }
            $currentValues = $currentGroup->values->values();
            if (count($groupData['values']) !== $currentValues->count()) {
                return false;
            }
            foreach ($groupData['values'] as $valueIndex => $valueData) {
                $currentValue = $currentValues->get($valueIndex);
                if ($currentValue === null || $currentValue->value !== $valueData['value']) {
                    return false;
                }
                $submittedParentKey = $valueData['parent_client_key'] ?? null;
                $currentParentKey = $currentValue->parent_product_option_value_id === null
                    ? null
                    : ($clientKeysByValueId[$currentValue->parent_product_option_value_id] ?? null);
                if ($submittedParentKey !== $currentParentKey) {
                    return false;
                }
                $clientKeysByValueId[$currentValue->id] = $valueData['client_key'];
            }
        }

        $currentVariants = $product->variants->keyBy('public_id');
        foreach ($variants as $variantData) {
            $publicId = $variantData['public_id'] ?? null;
            $currentVariant = is_string($publicId) ? $currentVariants->get($publicId) : null;
            if (! $currentVariant instanceof ProductVariant) {
                return false;
            }
            $currentKeys = $currentVariant->values
                ->map(fn ($value): ?string => $clientKeysByValueId[$value->id] ?? null)
                ->filter()
                ->sort()
                ->values()
                ->all();
            $submittedKeys = collect($variantData['option_value_client_keys'])->sort()->values()->all();
            if ($currentKeys !== $submittedKeys) {
                return false;
            }
        }

        return true;
    }

    /** @param array<int, array{public_id: string, sku?: string|null, regular_price_millimes?: int|null, promotional_price_millimes?: int|null, stock_quantity: int, low_stock_threshold?: int|null, is_active?: bool, is_default?: bool}> $variants */
    private function updateExistingVariants(Product $product, array $variants): void
    {
        $existingVariants = $product->variants()->get()->keyBy('public_id');
        foreach ($variants as $variantData) {
            /** @var ProductVariant $variant */
            $variant = $existingVariants->get($variantData['public_id']);
            $variant->update([
                'sku' => $variantData['sku'] ?? null,
                'regular_price_millimes' => $variantData['regular_price_millimes'] ?? null,
                'promotional_price_millimes' => $variantData['promotional_price_millimes'] ?? null,
                'stock_quantity' => $variantData['stock_quantity'],
                'low_stock_threshold' => $variantData['low_stock_threshold'] ?? null,
                'is_active' => $variantData['is_active'] ?? true,
                'is_default' => $variantData['is_default'] ?? false,
            ]);
        }
        $default = $product->variants()->where('is_default', true)->orderBy('id')->first();
        if ($default === null) {
            $product->variants()->where('is_active', true)->orderBy('id')->first()?->update(['is_default' => true]);
        } else {
            $product->variants()->where('is_default', true)->whereKeyNot($default->id)->update(['is_default' => false]);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $gallery
     * @param  array<string, string>  $stagedUploads
     * @param  Collection<int, ProductVariant>  $variants
     */
    private function syncGallery(Product $product, array $gallery, array $stagedUploads, Collection $variants): void
    {
        $existingImages = $product->images()->get()->keyBy('public_id');
        $keptImageIds = [];
        $primaryCount = 0;

        foreach ($gallery as $position => $media) {
            $role = ! $product->has_variants && $media['role'] === 'variant'
                ? 'secondary'
                : $media['role'];
            $isPrimary = $role === 'primary';
            $primaryCount += $isPrimary ? 1 : 0;
            $variant = $role === 'variant'
                ? $variants->get((int) $media['variant_index'])
                : null;
            if ($role === 'variant' && ! $variant instanceof ProductVariant) {
                throw ValidationException::withMessages(['gallery' => 'Une image de variante doit viser une variante existante.']);
            }

            if ($media['existing_public_id'] !== null) {
                /** @var ProductImage|null $image */
                $image = $existingImages->get($media['existing_public_id']);
                if (! $image) {
                    throw ValidationException::withMessages(['gallery' => 'Une image de galerie est invalide.']);
                }
                $image->update([
                    'alt_text' => $media['alt_text'],
                    'is_primary' => $isPrimary,
                    'product_variant_id' => $variant?->id,
                    'sort_order' => $position,
                ]);
                $keptImageIds[] = $image->id;

                continue;
            }

            $stagedPath = $stagedUploads[$media['upload_key']] ?? null;
            if (! $stagedPath) {
                throw ValidationException::withMessages(['gallery' => 'Une nouvelle image de galerie est manquante.']);
            }
            $image = $product->images()->create([
                'original_path' => $stagedPath,
                'alt_text' => $media['alt_text'],
                'is_primary' => $isPrimary,
                'product_variant_id' => $variant?->id,
                'sort_order' => $position,
                'processing_status' => 'pending',
            ]);
            $keptImageIds[] = $image->id;
            ProcessProductImage::dispatch($image->id)->afterCommit();
        }

        if ($primaryCount > 1) {
            throw ValidationException::withMessages(['gallery' => 'Une seule image principale est autorisée.']);
        }

        $existingImages->whereNotIn('id', $keptImageIds)->each(function (ProductImage $image): void {
            $paths = [$image->path, $image->original_path, $image->renditions];
            $image->delete();
            DeleteProductImageFiles::dispatch($paths[0], $paths[1], $paths[2])->afterCommit();
        });
    }
}
