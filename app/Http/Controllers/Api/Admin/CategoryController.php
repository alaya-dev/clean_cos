<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Audit\Actions\RecordAuditEventAction;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'is_active' => ['nullable', 'boolean'],
            'sort' => ['nullable', 'in:name,-name,sort_order,-sort_order,created_at,-created_at'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
            'leaf_only' => ['nullable', 'boolean'],
        ]);
        $sort = $data['sort'] ?? 'sort_order';
        $categories = Category::query()->when($data['leaf_only'] ?? false, fn ($query) => $query->where(function ($leafQuery): void {
            $leafQuery->whereNotNull('parent_id')->orWhereDoesntHave('subcategories');
        }), fn ($query) => $query->whereNull('parent_id'))->with([
            'subcategories' => fn ($query) => $query->withCount('products')->orderBy('sort_order')->orderBy('name'),
        ])->withCount('subcategories')
            ->when($data['search'] ?? null, fn ($query, $search) => $query->where('name', 'like', '%'.$search.'%'))
            ->when(array_key_exists('is_active', $data), fn ($query) => $query->where('is_active', $data['is_active']))
            ->orderBy(ltrim($sort, '-'), str_starts_with($sort, '-') ? 'desc' : 'asc')
            ->paginate($data['per_page'] ?? 25);

        $categories->getCollection()->transform(fn (Category $category) => $category->toArray());

        return response()->json(['data' => $categories]);
    }

    public function store(Request $request, RecordAuditEventAction $audit): JsonResponse
    {
        $data = $this->validated($request);
        $data['slug'] ??= Str::slug($data['name']);

        $category = Category::query()->create($data);
        $audit->handle('catalog.category_created', $category, $request->user());

        return response()->json(['data' => $category->toArray()], 201);
    }

    public function show(Category $category): JsonResponse
    {
        return response()->json(['data' => $category->toArray()]);
    }

    public function update(Request $request, Category $category, RecordAuditEventAction $audit): JsonResponse
    {
        $oldSlug = $category->slug;
        $data = $this->validated($request, false);
        DB::transaction(function () use ($category, $data): void {
            $category->update($data);
            if (array_key_exists('is_active', $data) && ! $data['is_active']) {
                $category->subcategories()->each(function (Category $subcategory): void {
                    $subcategory->update(['is_active' => false]);
                    $subcategory->products()->update(['is_active' => false]);
                });
                $category->products()->update(['is_active' => false]);
            }
        });
        if (isset($data['slug']) && $data['slug'] !== $oldSlug) {
            DB::table('url_redirects')->updateOrInsert(['from_path' => '/categories/'.$oldSlug], ['to_path' => '/categories/'.$category->slug, 'updated_at' => now(), 'created_at' => now()]);
        }

        $audit->handle('catalog.category_updated', $category, $request->user(), after: ['fields' => array_keys($data)]);

        $category->refresh();

        return response()->json(['data' => $category->toArray()]);
    }

    public function destroy(Request $request, Category $category, RecordAuditEventAction $audit): JsonResponse
    {
        $deletedAt = now();
        $deletedProducts = DB::transaction(function () use ($category, $deletedAt): int {
            Category::query()->whereKey($category->id)->lockForUpdate()->firstOrFail();

            $subcategoryIds = $category->subcategories()->lockForUpdate()->pluck('id');
            $deletedProducts = Product::query()
                ->whereIn('category_id', $subcategoryIds->push($category->id))
                ->lockForUpdate()
                ->update([
                    'is_active' => false,
                    'deleted_at' => $deletedAt,
                    'updated_at' => $deletedAt,
                ]);

            $category->subcategories()->delete();
            $category->delete();

            return $deletedProducts;
        });

        $audit->handle('catalog.category_deleted', $category, $request->user(), after: [
            'catalog_removal' => 'soft_deleted',
            'products_soft_deleted' => $deletedProducts,
            'order_history_preserved' => true,
        ]);

        return response()->json(['data' => ['deleted_products' => $deletedProducts]]);
    }

    public function reorder(Request $request, RecordAuditEventAction $audit): JsonResponse
    {
        $data = $request->validate(['items' => ['required', 'array', 'min:1', 'max:100'], 'items.*.public_id' => ['required', 'ulid', 'distinct'], 'items.*.sort_order' => ['required', 'integer', 'min:0']]);
        DB::transaction(function () use ($data): void {
            foreach ($data['items'] as $item) {
                Category::query()->where('public_id', $item['public_id'])->update(['sort_order' => $item['sort_order']]);
            }
        });
        $category = Category::query()->where('public_id', $data['items'][0]['public_id'])->firstOrFail();
        $audit->handle('catalog.categories_reordered', $category, $request->user(), after: ['count' => count($data['items'])]);

        return response()->json(['data' => null]);
    }

    public function productOrder(Category $category): JsonResponse
    {
        $products = $category->products()
            ->with(['images' => fn ($images) => $images->where('is_primary', true)->select(['id', 'product_id', 'path', 'processing_status', 'is_primary'])])
            ->orderBy('sort_order')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->get(['id', 'public_id', 'category_id', 'name', 'is_active', 'sort_order', 'published_at']);

        return response()->json(['data' => $products]);
    }

    public function updateProductOrder(Request $request, Category $category, RecordAuditEventAction $audit): JsonResponse
    {
        $data = $request->validate(['product_public_ids' => ['required', 'array', 'min:1', 'max:1000'], 'product_public_ids.*' => ['required', 'ulid', 'distinct']]);
        $productPublicIds = $data['product_public_ids'];
        $updatedAt = now();

        DB::transaction(function () use ($category, $productPublicIds, $updatedAt): void {
            $products = Product::query()->where('category_id', $category->id)->lockForUpdate()->get(['id', 'public_id']);
            abort_if($products->count() !== count($productPublicIds) || $products->pluck('public_id')->diff($productPublicIds)->isNotEmpty(), 422, 'La liste des produits de cette catégorie a changé. Actualisez-la puis réessayez.');

            foreach ($productPublicIds as $position => $productPublicId) {
                Product::query()->where('category_id', $category->id)->where('public_id', $productPublicId)->update(['sort_order' => $position, 'updated_at' => $updatedAt]);
            }
        });

        $category->touch();
        $audit->handle('catalog.category_products_reordered', $category, $request->user(), after: ['count' => count($productPublicIds)]);

        return response()->json(['data' => null]);
    }

    public function bulkStatus(Request $request, RecordAuditEventAction $audit): JsonResponse
    {
        $data = $request->validate([
            'public_ids' => ['required', 'array', 'min:1', 'max:100'],
            'public_ids.*' => ['required', 'ulid', 'distinct'],
            'is_active' => ['required', 'boolean'],
        ]);

        $categories = DB::transaction(function () use ($data) {
            $categories = Category::query()->whereIn('public_id', $data['public_ids'])->lockForUpdate()->get();
            abort_if($categories->count() !== count($data['public_ids']), 404);

            foreach ($categories as $category) {
                $category->update(['is_active' => $data['is_active']]);

                if (! $data['is_active']) {
                    $category->subcategories()->each(function (Category $subcategory): void {
                        $subcategory->update(['is_active' => false]);
                        $subcategory->products()->update(['is_active' => false]);
                    });
                    $category->products()->update(['is_active' => false]);
                }
            }

            return $categories;
        });

        $audit->handle('catalog.categories_bulk_status_updated', $categories->firstOrFail(), $request->user(), after: [
            'count' => $categories->count(),
            'is_active' => $data['is_active'],
        ]);

        return response()->json(['data' => ['updated' => $categories->count()]]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $required = true): array
    {
        $category = $request->route('category');
        $ignoreId = $category instanceof Category ? ','.$category->id : '';

        $data = $request->validate(['parent_public_id' => ['nullable', 'ulid', 'exists:categories,public_id'], 'name' => [$required ? 'required' : 'sometimes', 'string', 'between:2,160'], 'slug' => ['nullable', 'string', 'max:190', 'unique:categories,slug'.$ignoreId], 'description' => ['nullable', 'string', 'max:5000'], 'is_active' => [$required ? 'required' : 'sometimes', 'boolean'], 'sort_order' => [$required ? 'required' : 'sometimes', 'integer', 'min:0'], 'seo_title' => ['nullable', 'string', 'max:255'], 'seo_description' => ['nullable', 'string', 'max:320']]);

        if (array_key_exists('parent_public_id', $data)) {
            $parent = $data['parent_public_id'] === null ? null : Category::query()->where('public_id', $data['parent_public_id'])->firstOrFail();
            abort_if($parent?->parent_id !== null, 422, 'Une sous-catÃ©gorie ne peut pas contenir une autre sous-catÃ©gorie.');
            abort_if($category instanceof Category && $parent?->id === $category->id, 422, 'Une catÃ©gorie ne peut pas Ãªtre sa propre parente.');
            $data['parent_id'] = $parent?->id;
            unset($data['parent_public_id']);
        }

        return $data;
    }
}
