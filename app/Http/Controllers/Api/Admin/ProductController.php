<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Audit\Actions\RecordAuditEventAction;
use App\Domain\Catalog\Actions\CreateProductAction;
use App\Domain\Catalog\Actions\ReplaceProductVariantsAction;
use App\Domain\Catalog\Actions\SaveProductEditorAction;
use App\Domain\Catalog\Actions\SwitchProductVariantModeAction;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\InventoryMovement;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Services\CatalogCacheVersion;
use App\Domain\Commerce\Models\OrderItem;
use App\Domain\Content\Services\HomepageCache;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'category_id' => ['nullable', 'ulid'],
            'is_active' => ['nullable', 'boolean'],
            'has_variants' => ['nullable', 'boolean'],
            'stock_state' => ['nullable', 'in:in_stock,low_stock,out_of_stock'],
            'is_promotional' => ['nullable', 'boolean'],
            'archived' => ['nullable', 'boolean'],
            'created_from' => ['nullable', 'date'],
            'created_to' => ['nullable', 'date'],
            'sort' => ['nullable', 'in:name,-name,created_at,-created_at,regular_price_millimes,-regular_price_millimes'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);
        $query = Product::query()
            ->with(['category', 'images' => fn ($images) => $images->where('is_primary', true)->select(['id', 'product_id', 'path', 'processing_status', 'is_primary'])])
            ->withCount(['variants as current_variant_count'])
            ->withSum(['variants as active_variant_stock_quantity' => fn ($variants) => $variants->where('is_active', true)], 'stock_quantity');

        ($data['archived'] ?? false) ? $query->onlyTrashed() : $query->whereNull('deleted_at');

        if ($data['search'] ?? null) {
            $query->where('name', 'like', '%'.$data['search'].'%');
        }
        if ($data['category_id'] ?? null) {
            $query->whereHas('category', fn ($category) => $category->where('public_id', $data['category_id']));
        }
        foreach (['is_active', 'has_variants'] as $filter) {
            if (array_key_exists($filter, $data)) {
                $query->where($filter, $data[$filter]);
            }
        }
        if (array_key_exists('is_promotional', $data)) {
            $data['is_promotional'] ? $query->whereNotNull('promotional_price_millimes') : $query->whereNull('promotional_price_millimes');
        }
        if ($data['created_from'] ?? null) {
            $query->whereDate('created_at', '>=', $data['created_from']);
        }
        if ($data['created_to'] ?? null) {
            $query->whereDate('created_at', '<=', $data['created_to']);
        }
        if ($data['stock_state'] ?? null) {
            $state = $data['stock_state'];
            $query->where(function ($stock) use ($state): void {
                if ($state === 'out_of_stock') {
                    $stock->where('has_variants', false)->where('stock_quantity', 0);
                } elseif ($state === 'low_stock') {
                    $stock->where('has_variants', false)->whereColumn('stock_quantity', '<=', 'low_stock_threshold')->where('stock_quantity', '>', 0);
                } else {
                    $stock->where('has_variants', false)->where('stock_quantity', '>', 0);
                }
            });
        }
        $sort = $data['sort'] ?? '-created_at';
        $query->orderBy(ltrim($sort, '-'), str_starts_with($sort, '-') ? 'desc' : 'asc');

        $products = $query->paginate($data['per_page'] ?? 25);
        $products->getCollection()->each(function (Product $product): void {
            if ($product->has_variants) {
                $product->setAttribute('active_variant_stock_quantity', (int) ($product->active_variant_stock_quantity ?? 0));
            }
        });

        return response()->json(['data' => $products]);
    }

    public function store(Request $request, CreateProductAction $action, RecordAuditEventAction $audit): JsonResponse
    {
        $catalogId = $this->normalizeCatalogId($request->input('meta_catalog_id'));
        if ($catalogId !== null && $request->user()?->role !== 'super_admin') {
            abort(403);
        }
        if ($catalogId !== null && Product::query()->where('meta_catalog_id', $catalogId)->exists()) {
            return response()->json(['code' => 'META_CATALOG_ID_DUPLICATE', 'message' => 'Cet identifiant catalogue Meta est déjà utilisé.'], 422);
        }
        $data = $request->validate(['category_public_id' => ['required', 'ulid'], 'name' => ['required', 'string', 'max:200'], 'slug' => ['required', 'string', 'max:190', 'unique:products,slug'], 'meta_catalog_id' => ['nullable', 'string', 'max:120', 'not_regex:/[<>]/'], 'regular_price_millimes' => ['required', 'integer', 'min:0'], 'promotional_price_millimes' => ['nullable', 'integer', 'min:0'], 'stock_quantity' => ['nullable', 'integer', 'min:0'], 'low_stock_threshold' => ['nullable', 'integer', 'min:0'], 'is_active' => ['required', 'boolean'], 'has_variants' => ['required', 'boolean'], 'short_description' => ['nullable', 'string'], 'full_description' => ['nullable', 'string'], 'published_at' => ['nullable', 'date'], 'seo_title' => ['nullable', 'string', 'max:255'], 'seo_description' => ['nullable', 'string', 'max:320'], 'option_groups' => ['nullable', 'array', 'max:5'], 'variants' => ['nullable', 'array', 'max:250'], 'variants.*.meta_catalog_id' => ['prohibited']]);
        if (array_key_exists('meta_catalog_id', $data)) {
            $data['meta_catalog_id'] = $this->normalizeCatalogId($data['meta_catalog_id']);
            if ($request->user()?->role !== 'super_admin') {
                abort_if($data['meta_catalog_id'] !== null, 403);
                unset($data['meta_catalog_id']);
            }
        }
        $product = $action->handle($data);
        $audit->handle('catalog.product_created', $product, $request->user(), after: ['public_id' => $product->public_id, 'meta_catalog_id' => $product->meta_catalog_id]);

        return response()->json(['data' => $product], 201);
    }

    public function saveEditor(Request $request, SaveProductEditorAction $action, RecordAuditEventAction $audit): JsonResponse
    {
        try {
            $payload = json_decode((string) $request->input('payload'), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ValidationException::withMessages(['payload' => 'Les données de l’éditeur sont invalides.']);
        }

        if (! is_array($payload)) {
            throw ValidationException::withMessages(['payload' => 'Les données de l’éditeur sont invalides.']);
        }

        $existingProduct = null;
        if (isset($payload['product_public_id'])) {
            $existingProduct = Product::query()->where('public_id', $payload['product_public_id'])->firstOrFail();
        }
        $data = $this->validateEditorPayload($payload, $existingProduct);
        $catalogId = $this->normalizeCatalogId($data['meta_catalog_id']);
        if ($catalogId !== null && $request->user()?->role !== 'super_admin') {
            abort(403);
        }
        $existingProductId = $existingProduct?->id;
        if ($catalogId !== null && Product::query()->where('meta_catalog_id', $catalogId)->when($existingProductId !== null, fn ($query) => $query->whereKeyNot($existingProductId))->exists()) {
            return response()->json(['code' => 'META_CATALOG_ID_DUPLICATE', 'message' => 'Cet identifiant catalogue Meta est déjà utilisé.'], 422);
        }
        $data['meta_catalog_id'] = $catalogId;
        if ($existingProduct?->meta_catalog_id !== null && $existingProduct->meta_catalog_id !== $catalogId && ($data['meta_catalog_id_confirmation'] ?? false) !== true) {
            return response()->json(['code' => 'META_CATALOG_ID_CONFIRMATION_REQUIRED', 'message' => 'Confirmez explicitement le remplacement de l’identifiant catalogue Meta existant.'], 422);
        }

        $uploads = $this->normalizeEditorUploads($request->file('uploads', []));
        $this->validateEditorUploads($uploads);
        $previousSlug = $existingProduct?->slug;
        $previousCatalogId = $existingProduct?->meta_catalog_id;
        $savedProduct = $action->handle($existingProduct, $data, $uploads);
        $audit->handle($existingProduct ? 'catalog.product_updated' : 'catalog.product_created', $savedProduct, $request->user(), after: ['fields' => array_keys($data)]);
        if ($existingProduct !== null && $previousCatalogId !== $savedProduct->meta_catalog_id) {
            $audit->handle('catalog.meta_catalog_id_changed', $savedProduct, $request->user(), before: ['meta_catalog_id' => $previousCatalogId], after: ['meta_catalog_id' => $savedProduct->meta_catalog_id]);
        }

        if ($previousSlug !== null && $previousSlug !== $savedProduct->slug) {
            DB::table('url_redirects')->updateOrInsert(['from_path' => '/produits/'.$previousSlug], ['to_path' => '/produits/'.$savedProduct->slug, 'updated_at' => now(), 'created_at' => now()]);
        }

        return response()->json(['data' => $savedProduct], $existingProduct ? 200 : 201);
    }

    public function show(Product $product): JsonResponse
    {
        return response()->json(['data' => $product->load('category', 'images.variant', 'optionGroups.values.parentValue', 'variants.values.parentValue')]);
    }

    public function mediaStatus(Product $product): JsonResponse
    {
        $images = $product->images()
            ->where('is_primary', true)
            ->select(['id', 'public_id', 'product_id', 'path', 'processing_status', 'is_primary'])
            ->get();

        return response()->json(['data' => [
            'public_id' => $product->public_id,
            'images' => $images,
        ]]);
    }

    public function update(Request $request, Product $product, RecordAuditEventAction $audit): JsonResponse
    {
        $catalogId = $this->normalizeCatalogId($request->input('meta_catalog_id'));
        if ($catalogId !== null && $request->user()?->role !== 'super_admin') {
            abort(403);
        }
        if ($catalogId !== null && Product::query()->where('meta_catalog_id', $catalogId)->where('id', '<>', $product->id)->exists()) {
            return response()->json(['code' => 'META_CATALOG_ID_DUPLICATE', 'message' => 'Cet identifiant catalogue Meta est déjà utilisé.'], 422);
        }
        $data = $request->validate(['category_public_id' => ['sometimes', 'ulid'], 'name' => ['sometimes', 'string', 'max:200'], 'slug' => ['sometimes', 'string', 'max:190', 'unique:products,slug,'.$product->id], 'meta_catalog_id' => ['nullable', 'string', 'max:120', 'not_regex:/[<>]/'], 'meta_catalog_id_confirmation' => ['nullable', 'boolean'], 'short_description' => ['nullable', 'string'], 'full_description' => ['nullable', 'string'], 'regular_price_millimes' => ['sometimes', 'integer', 'min:0'], 'promotional_price_millimes' => ['nullable', 'integer', 'min:0'], 'stock_quantity' => ['nullable', 'integer', 'min:0'], 'low_stock_threshold' => ['nullable', 'integer', 'min:0'], 'is_active' => ['sometimes', 'boolean'], 'published_at' => ['nullable', 'date'], 'seo_title' => ['nullable', 'string', 'max:255'], 'seo_description' => ['nullable', 'string', 'max:320']]);
        if (array_key_exists('meta_catalog_id', $data)) {
            $data['meta_catalog_id'] = $this->normalizeCatalogId($data['meta_catalog_id']);
            if ($request->user()?->role !== 'super_admin') {
                abort_if($data['meta_catalog_id'] !== null, 403);
                $data['meta_catalog_id'] = $product->meta_catalog_id;
            }
            if ($product->meta_catalog_id !== null && $product->meta_catalog_id !== $data['meta_catalog_id'] && ($data['meta_catalog_id_confirmation'] ?? false) !== true) {
                return response()->json(['code' => 'META_CATALOG_ID_CONFIRMATION_REQUIRED', 'message' => 'Confirmez explicitement le remplacement de l’identifiant catalogue Meta existant.'], 422);
            }
            unset($data['meta_catalog_id_confirmation']);
        }
        if ($product->has_variants && (array_key_exists('stock_quantity', $data) || array_key_exists('low_stock_threshold', $data))) {
            return response()->json(['code' => 'VARIANT_STOCK_MANAGED_SEPARATELY', 'message' => 'Le stock d’un produit à variantes se gère par variante.'], 422);
        }
        if (isset($data['category_public_id'])) {
            $data['category_id'] = Category::query()->where('public_id', $data['category_public_id'])->firstOrFail()->id;
            unset($data['category_public_id']);
            if ($data['category_id'] !== $product->category_id) {
                $data['sort_order'] = ((int) (Product::query()->where('category_id', $data['category_id'])->max('sort_order') ?? -1)) + 1;
            }
        }
        $regular = $data['regular_price_millimes'] ?? $product->regular_price_millimes;
        if (($data['promotional_price_millimes'] ?? $product->promotional_price_millimes) !== null && ($data['promotional_price_millimes'] ?? $product->promotional_price_millimes) >= $regular) {
            return response()->json(['code' => 'VALIDATION_FAILED', 'message' => 'Le prix promotionnel doit être inférieur au prix normal.'], 422);
        }
        $previousSlug = $product->slug;
        $previousCatalogId = $product->meta_catalog_id;
        $product->update($data);
        $audit->handle('catalog.product_updated', $product, $request->user(), before: ['meta_catalog_id' => $previousCatalogId], after: ['fields' => array_keys($data), 'meta_catalog_id' => $product->meta_catalog_id]);
        if (array_key_exists('meta_catalog_id', $data) && $previousCatalogId !== $product->meta_catalog_id) {
            $audit->handle('catalog.meta_catalog_id_changed', $product, $request->user(), before: ['meta_catalog_id' => $previousCatalogId], after: ['meta_catalog_id' => $product->meta_catalog_id]);
        }
        if (isset($data['slug']) && $data['slug'] !== $previousSlug) {
            DB::table('url_redirects')->updateOrInsert(['from_path' => '/produits/'.$previousSlug], ['to_path' => '/produits/'.$product->slug, 'updated_at' => now(), 'created_at' => now()]);
        }

        return response()->json(['data' => $product->fresh()]);
    }

    public function destroy(Request $request, Product $product, RecordAuditEventAction $audit, CatalogCacheVersion $catalogCache, HomepageCache $homepageCache): JsonResponse
    {
        DB::transaction(function () use ($request, $product, $audit): void {
            Product::query()->whereKey($product->id)->update([
                'is_active' => false,
                'deleted_at' => now(),
                'updated_at' => now(),
            ]);
            $audit->handle('catalog.product_deleted', $product, $request->user(), after: [
                'catalog_removal' => 'soft_deleted',
                'order_history_preserved' => true,
            ]);
        });
        $catalogCache->bump();
        $homepageCache->forget();

        return response()->json(['data' => null]);
    }

    public function status(Request $request, Product $product): JsonResponse
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        $product->update(['is_active' => $data['is_active'], 'published_at' => $data['is_active'] ? ($product->published_at ?? now()) : $product->published_at]);

        return response()->json(['data' => $product->fresh()]);
    }

    public function bulkStatus(Request $request): JsonResponse
    {
        $data = $request->validate(['public_ids' => ['required', 'array', 'min:1', 'max:100'], 'public_ids.*' => ['ulid', 'distinct'], 'is_active' => ['required', 'boolean']]);
        $updated = DB::transaction(function () use ($data): int {
            $products = Product::query()->whereIn('public_id', $data['public_ids'])->lockForUpdate()->get();
            abort_if($products->count() !== count($data['public_ids']), 404);

            foreach ($products as $product) {
                $product->update([
                    'is_active' => $data['is_active'],
                    'published_at' => $data['is_active'] ? ($product->published_at ?? now()) : $product->published_at,
                ]);
            }

            return $products->count();
        });

        return response()->json(['data' => ['updated' => $updated]]);
    }

    public function bulkSetStock(Request $request, RecordAuditEventAction $audit): JsonResponse
    {
        $data = $request->validate([
            'public_ids' => ['required', 'array', 'min:1', 'max:100'],
            'public_ids.*' => ['required', 'ulid', 'distinct'],
            'stock_quantity' => ['required', 'integer', 'min:0'],
        ]);

        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        [$products, $updatedVariants] = DB::transaction(function () use ($data, $actor): array {
            $products = Product::query()->whereIn('public_id', $data['public_ids'])->lockForUpdate()->get();
            abort_if($products->count() !== count($data['public_ids']), 404);
            $variantsByProduct = ProductVariant::query()
                ->whereIn('product_id', $products->modelKeys())
                ->where('is_current', true)
                ->lockForUpdate()
                ->get()
                ->groupBy('product_id');
            $updatedVariants = 0;

            foreach ($products as $product) {
                if ($product->has_variants) {
                    $variants = $variantsByProduct->get($product->id, collect());
                    if ($variants->isEmpty()) {
                        throw ValidationException::withMessages([
                            'public_ids' => 'Un produit à variantes doit avoir au moins une variante avant une mise à jour groupée du stock.',
                        ]);
                    }

                    foreach ($variants as $variant) {
                        $this->setStock($product, $variant, $data['stock_quantity'], $actor->id);
                        $updatedVariants++;
                    }

                    continue;
                }

                $this->setStock($product, null, $data['stock_quantity'], $actor->id);
            }

            return [$products, $updatedVariants];
        });

        $audit->handle('catalog.products_bulk_stock_set', $products->firstOrFail(), $actor, after: [
            'count' => $products->count(),
            'stock_quantity' => $data['stock_quantity'],
            'updated_variants' => $updatedVariants,
        ]);

        return response()->json(['data' => ['updated' => $products->count(), 'updated_variants' => $updatedVariants]]);
    }

    public function bulkSetPromotion(Request $request, RecordAuditEventAction $audit): JsonResponse
    {
        $data = $request->validate([
            'public_ids' => ['required', 'array', 'min:1', 'max:100'],
            'public_ids.*' => ['required', 'ulid', 'distinct'],
            'percentage' => ['required', 'integer', 'between:1,99'],
        ]);

        $products = DB::transaction(function () use ($data) {
            $products = Product::query()->whereIn('public_id', $data['public_ids'])->lockForUpdate()->get();
            abort_if($products->count() !== count($data['public_ids']), 404);

            foreach ($products as $product) {
                $promotionalPrice = intdiv(
                    ($product->regular_price_millimes * (100 - $data['percentage'])) + 50,
                    100,
                );
                $product->update(['promotional_price_millimes' => $promotionalPrice]);
            }

            return $products;
        });

        $audit->handle('catalog.products_bulk_promotion_set', $products->firstOrFail(), $request->user(), after: [
            'count' => $products->count(),
            'percentage' => $data['percentage'],
        ]);

        return response()->json(['data' => ['updated' => $products->count()]]);
    }

    private function setStock(Product $product, ?ProductVariant $variant, int $quantity, int $actorId): void
    {
        $stockTarget = $variant ?? $product;
        $before = (int) $stockTarget->stock_quantity;
        $stockTarget->update(['stock_quantity' => $quantity]);
        InventoryMovement::query()->create([
            'product_id' => $product->id,
            'product_variant_id' => $variant?->id,
            'actor_user_id' => $actorId,
            'type' => 'manual_adjustment',
            'quantity_delta' => $quantity - $before,
            'quantity_before' => $before,
            'quantity_after' => $quantity,
            'reason' => 'Mise à niveau groupée du stock',
        ]);
    }

    public function bulkArchive(Request $request): JsonResponse
    {
        $data = $request->validate(['public_ids' => ['required', 'array', 'min:1', 'max:100'], 'public_ids.*' => ['ulid', 'distinct']]);
        $archived = DB::transaction(function () use ($data): int {
            $products = Product::query()->whereIn('public_id', $data['public_ids'])->lockForUpdate()->get();
            abort_if($products->count() !== count($data['public_ids']), 404);

            foreach ($products as $product) {
                $product->update(['is_active' => false]);
                $product->delete();
            }

            return $products->count();
        });

        return response()->json(['data' => ['archived' => $archived]]);
    }

    public function bulkRestore(Request $request): JsonResponse
    {
        $data = $request->validate(['public_ids' => ['required', 'array', 'min:1', 'max:100'], 'public_ids.*' => ['ulid', 'distinct']]);
        $restored = DB::transaction(function () use ($data): int {
            $products = Product::withTrashed()->onlyTrashed()->whereIn('public_id', $data['public_ids'])->lockForUpdate()->get();
            abort_if($products->count() !== count($data['public_ids']), 404);

            foreach ($products as $product) {
                $product->restore();
            }

            return $products->count();
        });

        return response()->json(['data' => ['restored' => $restored]]);
    }

    public function bulkForceDelete(Request $request): JsonResponse
    {
        $data = $request->validate(['public_ids' => ['required', 'array', 'min:1', 'max:100'], 'public_ids.*' => ['ulid', 'distinct']]);
        $deleted = DB::transaction(function () use ($data): int {
            $products = Product::withTrashed()->onlyTrashed()->whereIn('public_id', $data['public_ids'])->lockForUpdate()->get();
            abort_if($products->count() !== count($data['public_ids']), 404);
            $productIds = $products->pluck('id');
            if (OrderItem::query()->whereIn('product_id', $productIds)->exists() || InventoryMovement::query()->whereIn('product_id', $productIds)->exists()) {
                throw ValidationException::withMessages(['public_ids' => 'Un produit sélectionné possède un historique de commande ou de stock. Archivez-le à la place.']);
            }

            // Remove variant pivots before the product's option groups/values
            // are deleted by database cascades. The option-value foreign key
            // intentionally restricts deletion, and MySQL may cascade the
            // product hierarchy in an order that would otherwise violate it.
            $variantIds = ProductVariant::query()
                ->whereIn('product_id', $productIds)
                ->lockForUpdate()
                ->pluck('id');

            if ($variantIds->isNotEmpty()) {
                DB::table('product_variant_values')
                    ->whereIn('product_variant_id', $variantIds)
                    ->delete();
            }

            foreach ($products as $product) {
                $product->forceDelete();
            }

            return $products->count();
        });

        return response()->json(['data' => ['deleted' => $deleted]]);
    }

    public function variantMode(Request $request, Product $product, SwitchProductVariantModeAction $action): JsonResponse
    {
        $data = $request->validate(['has_variants' => ['required', 'boolean'], 'confirmation' => ['nullable', 'in:CONFIRMER'], 'resulting_stock_quantity' => ['nullable', 'integer', 'min:0']]);

        return response()->json(['data' => $action->handle($product, $data['has_variants'], $data['resulting_stock_quantity'] ?? null)]);
    }

    public function replaceVariants(Request $request, Product $product, ReplaceProductVariantsAction $action): JsonResponse
    {
        $data = $request->validate(['lock_version' => ['required', 'integer', 'min:1'], 'option_groups' => ['required', 'array', 'min:1', 'max:5'], 'option_groups.*.name' => ['required', 'string', 'max:120'], 'option_groups.*.values' => ['required', 'array', 'min:1', 'max:250'], 'option_groups.*.values.*.client_key' => ['required', 'string', 'max:120', 'distinct'], 'option_groups.*.values.*.value' => ['required', 'string', 'max:120'], 'option_groups.*.values.*.parent_client_key' => ['nullable', 'string', 'max:120'], 'variants' => ['required', 'array', 'min:1', 'max:250'], 'variants.*.option_value_client_keys' => ['required', 'array'], 'variants.*.stock_quantity' => ['required', 'integer', 'min:0'], 'variants.*.sku' => ['nullable', 'string', 'max:100'], 'variants.*.regular_price_millimes' => ['nullable', 'integer', 'min:0'], 'variants.*.promotional_price_millimes' => ['nullable', 'integer', 'min:0'], 'variants.*.low_stock_threshold' => ['nullable', 'integer', 'min:0'], 'variants.*.is_active' => ['nullable', 'boolean'], 'variants.*.is_default' => ['nullable', 'boolean'], 'variants.*.meta_catalog_id' => ['prohibited']]);

        return response()->json(['data' => $action->handle($product, $data['option_groups'], $data['variants'], $data['lock_version'])]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function validateEditorPayload(array $payload, ?Product $product): array
    {
        $slugRule = Rule::unique('products', 'slug');
        if ($product) {
            $slugRule->ignore($product->id);
        }

        return Validator::make($payload, [
            'category_public_id' => ['required', 'ulid', 'exists:categories,public_id'],
            'name' => ['required', 'string', 'max:200'],
            'slug' => ['required', 'string', 'max:190', $slugRule],
            'meta_catalog_id' => ['nullable', 'string', 'max:120', 'not_regex:/[<>]/'],
            'meta_catalog_id_confirmation' => ['nullable', 'boolean'],
            'regular_price_millimes' => ['required', 'integer', 'min:0'],
            'promotional_price_millimes' => ['nullable', 'integer', 'min:0'],
            'stock_quantity' => ['nullable', 'integer', 'min:0'],
            'low_stock_threshold' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['required', 'boolean'],
            'has_variants' => ['required', 'boolean'],
            'short_description' => ['nullable', 'string'],
            'full_description' => ['nullable', 'string'],
            'seo_title' => ['nullable', 'string', 'max:255'],
            'seo_description' => ['nullable', 'string', 'max:320'],
            'lock_version' => ['nullable', 'integer', 'min:1'],
            'option_groups' => ['required_if:has_variants,true', 'array', 'max:5'],
            'option_groups.*.name' => ['required', 'string', 'max:120'],
            'option_groups.*.values' => ['required', 'array', 'min:1', 'max:250'],
            'option_groups.*.values.*.client_key' => ['required', 'string', 'max:120', 'distinct'],
            'option_groups.*.values.*.value' => ['required', 'string', 'max:120'],
            'option_groups.*.values.*.parent_client_key' => ['nullable', 'string', 'max:120'],
            'variants' => ['required_if:has_variants,true', 'array', 'max:250'],
            'variants.*.public_id' => ['nullable', 'ulid'],
            'variants.*.option_value_client_keys' => ['required', 'array'],
            'variants.*.stock_quantity' => ['required', 'integer', 'min:0'],
            'variants.*.sku' => ['nullable', 'string', 'max:100'],
            'variants.*.regular_price_millimes' => ['nullable', 'integer', 'min:0'],
            'variants.*.promotional_price_millimes' => ['nullable', 'integer', 'min:0'],
            'variants.*.low_stock_threshold' => ['nullable', 'integer', 'min:0'],
            'variants.*.is_active' => ['nullable', 'boolean'],
            'variants.*.is_default' => ['nullable', 'boolean'],
            'gallery' => ['nullable', 'array', 'max:150'],
            'gallery.*.existing_public_id' => ['nullable', 'ulid', 'distinct'],
            'gallery.*.upload_key' => ['nullable', 'string', 'max:120', 'distinct'],
            'gallery.*.alt_text' => ['nullable', 'string', 'max:255'],
            'gallery.*.role' => ['required', 'in:primary,secondary,variant'],
            'gallery.*.variant_index' => ['nullable', 'integer', 'min:0', 'max:249'],
        ])->after(function ($validator): void {
            foreach ($validator->getData()['gallery'] ?? [] as $index => $media) {
                if (($media['existing_public_id'] ?? null) === null && ($media['upload_key'] ?? null) === null) {
                    $validator->errors()->add("gallery.$index", 'Une image doit être existante ou nouvellement ajoutée.');
                }
                if (($media['role'] ?? null) === 'variant' && ! array_key_exists('variant_index', $media)) {
                    $validator->errors()->add("gallery.$index.variant_index", 'Choisissez une variante pour cette image.');
                }
            }
        })->validate();
    }

    /** @param array<string, UploadedFile> $uploads */
    private function validateEditorUploads(array $uploads): void
    {
        foreach ($uploads as $upload) {
            Validator::make(['image' => $upload], [
                'image' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048', 'dimensions:max_width=8000,max_height=8000'],
            ], ['image.max' => 'L’image ne doit pas dépasser 2 Mo.'])->validate();
            $imageInfo = @getimagesize($upload->getRealPath());
            abort_unless($imageInfo !== false && in_array($imageInfo['mime'], ['image/jpeg', 'image/png', 'image/webp'], true), 422, 'Fichier image invalide.');
            abort_if(($imageInfo[0] * $imageInfo[1]) > 20_000_000, 422, 'L’image est trop grande.');
        }
    }

    /** @return array<string, UploadedFile> */
    private function normalizeEditorUploads(mixed $uploads): array
    {
        if (! is_array($uploads)) {
            throw ValidationException::withMessages(['uploads' => 'Les images sont invalides.']);
        }

        $normalized = [];
        foreach ($uploads as $key => $upload) {
            if (! is_string($key) || ! $upload instanceof UploadedFile) {
                throw ValidationException::withMessages(['uploads' => 'Les images sont invalides.']);
            }
            $normalized[$key] = $upload;
        }

        return $normalized;
    }

    private function normalizeCatalogId(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
    }

    // Variant catalogue mappings are intentionally not part of the admin API.

    /*
    $ids = array_values(array_filter(
        array_map(fn (array $variant): ?string => $this->normalizeCatalogId($variant['legacy_catalogue_id'] ?? null), $variants),
        static fn (?string $id): bool => $id !== null,
    ));
    if (count($ids) !== count(array_unique($ids))) {
        throw ValidationException::withMessages(['variants' => 'Chaque identifiant catalogue Meta de variante doit être unique.']);
    }
    $query = Product::query()->whereIn('meta_catalog_id', $ids);
    if ($product !== null) {
        $query->where('product_id', '<>', $product->id);
    }
    if ($ids !== [] && $query->exists()) {
        throw ValidationException::withMessages(['variants' => 'Un identifiant catalogue Meta de variante est déjà utilisé.']);
    }
    }

    // private function hasVariantCatalogMapping(array $variants): bool
    {
    foreach ($variants as $variant) {
        if ($this->normalizeCatalogId($variant['legacy_catalogue_id'] ?? null) !== null) {
            return true;
        }
    }

    return false;
    }
    */
}
