<?php

namespace App\Http\Controllers;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\CatalogCacheVersion;
use App\Domain\Commerce\Models\Order;
use App\Domain\Content\Services\HomepageContentService;
use App\Domain\MetaTracking\Models\MetaEvent;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class StorefrontCatalogController extends Controller
{
    public function cart(): View
    {
        return view('storefront.cart');
    }

    public function checkout(): View
    {
        return view('storefront.checkout');
    }

    public function confirmation(Order $order): View
    {
        $purchaseEvent = MetaEvent::query()
            ->where('order_id', $order->id)
            ->where('event_name', 'Purchase')
            ->where('marketing_consent', true)
            ->whereNotIn('capi_state', ['skipped_no_consent', 'skipped_tracking_disabled', 'skipped_no_active_configuration'])
            ->first();

        return view('storefront.confirmation', compact('order', 'purchaseEvent'));
    }

    public function home(HomepageContentService $content): View
    {
        return view('storefront.home', $content->viewModel());
    }

    public function products(Request $request): View
    {
        $categories = $this->activeSubcategories();
        $products = $this->applyFilters($this->catalogueQuery(), $request);
        $this->applyDefaultCatalogueOrder($products, $request);
        $products = $products->paginate(20)->withQueryString();

        return view('storefront.products.index', compact('categories', 'products'));
    }

    public function category(Request $request, string $slug): View|RedirectResponse
    {
        $category = Category::query()->where('slug', $slug)->where('is_active', true)->first();
        if (! $category) {
            return $this->redirectForLegacyPath('/categories/'.$slug) ?? abort(404);
        }
        if ($category->parent_id === null && $category->subcategories()->where('is_active', true)->exists()) {
            $subcategories = $category->subcategories()->where('is_active', true)->orderBy('sort_order')->get();

            return view('storefront.categories.show', compact('category', 'subcategories'));
        }
        $categories = $this->activeSubcategories();
        $products = $this->applyFilters($this->catalogueQuery()->where('category_id', $category->id), $request);
        $this->applyDefaultCategoryOrder($products, $request);
        $products = $products->paginate(20)->withQueryString();

        return view('storefront.categories.show', compact('category', 'categories', 'products'));
    }

    public function product(string $slug): View|RedirectResponse
    {
        $version = app(CatalogCacheVersion::class)->current();
        $product = Cache::remember("pc:cache:storefront:product:{$slug}:{$version}", now()->addMinutes(10), fn () => $this->productDetailQuery()->where('slug', $slug)->first());
        if (! $product) {
            return $this->redirectForLegacyPath('/produits/'.$slug) ?? abort(404);
        }
        $relatedProducts = $this->catalogueCardQuery()
            ->where('category_id', $product->category_id)
            ->whereKeyNot($product->id)
            ->limit(4)
            ->get();

        return view('storefront.products.show', compact('product', 'relatedProducts'));
    }

    public function search(Request $request): View
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $query = trim($data['q'] ?? '');
        $products = $query === ''
            ? collect()
            : $this->searchProducts($request, $query);
        $categories = $query === ''
            ? collect()
            : Category::query()->where('is_active', true)->where('name', 'like', '%'.$query.'%')->orderBy('name')->limit(8)->get();

        return view('storefront.search', compact('categories', 'products', 'query'));
    }

    /** @return Builder<Product> */
    private function catalogueQuery(): Builder
    {
        return $this->catalogueCardQuery();
    }

    /** @return Collection<int, Category> */
    private function activeSubcategories(): Collection
    {
        return Category::query()->where('is_active', true)->where(function (Builder $query): void {
            $query->whereNotNull('parent_id')->orWhereDoesntHave('subcategories');
        })->orderBy('sort_order')->get();
    }

    /** @return Builder<Product> */
    private function catalogueCardQuery(): Builder
    {
        return Product::public()
            ->with([
                'category:id,name,slug',
                'images' => fn ($query) => $query->where('processing_status', 'ready')->orderByDesc('is_primary')->orderBy('sort_order'),
            ]);
    }

    /** @return Builder<Product> */
    private function productDetailQuery(): Builder
    {
        return $this->catalogueCardQuery()->with(['variants.values', 'optionGroups.values.parentValue']);
    }

    /** @param Builder<Product> $query
     * @return Builder<Product>
     */
    private function applyFilters(Builder $query, Request $request): Builder
    {
        $minimumPrice = $this->millimesFromDinars($request->string('min_price_dt')->toString());
        $maximumPrice = $this->millimesFromDinars($request->string('max_price_dt')->toString());
        $categorySlug = $request->string('category')->toString();
        $sort = $request->string('sort')->toString();

        $hasActiveCategory = $categorySlug !== '' && mb_strlen($categorySlug) <= 120 && Category::query()
            ->where('slug', $categorySlug)
            ->where('is_active', true)
            ->exists();
        if ($hasActiveCategory) {
            $query->whereHas('category', fn (Builder $categoryQuery) => $categoryQuery->where('slug', $categorySlug)->where('is_active', true));
        }
        if ($minimumPrice !== null) {
            $query->whereRaw('COALESCE(promotional_price_millimes, regular_price_millimes) >= ?', [$minimumPrice]);
        }
        if ($maximumPrice !== null) {
            $query->whereRaw('COALESCE(promotional_price_millimes, regular_price_millimes) <= ?', [$maximumPrice]);
        }
        if ($request->boolean('promotions')) {
            $query->whereNotNull('promotional_price_millimes');
        }

        match ($sort) {
            'name_asc' => $query->orderBy('name'),
            'price_asc' => $query->orderByRaw('COALESCE(promotional_price_millimes, regular_price_millimes)'),
            'price_desc' => $query->orderByRaw('COALESCE(promotional_price_millimes, regular_price_millimes) DESC'),
            default => null,
        };

        return $query;
    }

    /** @param Builder<Product> $query */
    private function applyDefaultCatalogueOrder(Builder $query, Request $request): void
    {
        if ($this->hasExplicitSort($request)) {
            return;
        }

        $query->orderBy(Category::query()->select('sort_order')->whereColumn('categories.id', 'products.category_id'))
            ->orderBy('products.category_id')
            ->orderBy('products.sort_order')
            ->orderByDesc('products.published_at')
            ->orderByDesc('products.id');
    }

    /** @param Builder<Product> $query */
    private function applyDefaultCategoryOrder(Builder $query, Request $request): void
    {
        if ($this->hasExplicitSort($request)) {
            return;
        }

        $query->orderBy('sort_order')->orderByDesc('published_at')->orderByDesc('id');
    }

    private function hasExplicitSort(Request $request): bool
    {
        return in_array($request->string('sort')->toString(), [
            'name_asc',
            'price_asc',
            'price_desc',
        ], true);
    }

    /** @return LengthAwarePaginator<int, Product> */
    private function searchProducts(Request $request, string $query): LengthAwarePaginator
    {
        $products = $this->applyFilters($this->catalogueQuery()->where('name', 'like', '%'.$query.'%'), $request);
        $this->applyDefaultCatalogueOrder($products, $request);

        return $products->paginate(20)->withQueryString();
    }

    private function millimesFromDinars(string $amount): ?int
    {
        $normalized = str_replace(',', '.', trim($amount));
        if ($normalized === '' || ! preg_match('/^\d+(?:\.\d{1,3})?$/', $normalized)) {
            return null;
        }

        $parts = explode('.', $normalized, 2);
        $whole = $parts[0];
        $fraction = $parts[1] ?? '';

        return ((int) $whole * 1000) + (int) str_pad($fraction, 3, '0');
    }

    private function redirectForLegacyPath(string $path): ?RedirectResponse
    {
        $destination = DB::table('url_redirects')->where('from_path', $path)->value('to_path');

        return $destination ? redirect($destination, 301) : null;
    }
}
