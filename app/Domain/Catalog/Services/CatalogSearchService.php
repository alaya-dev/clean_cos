<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Support\Media\PublicMediaUrl;
use Illuminate\Support\Facades\Cache;

class CatalogSearchService
{
    public function __construct(private CatalogCacheVersion $versions) {}

    /**
     * @return array{
     *     products: array<int, array{public_id: string, name: string, slug: string, primary_image_url: string|null, effective_price: array{millimes: int, formatted: string}}>,
     *     categories: array<int, array{public_id: string, name: string, slug: string}>
     * }
     */
    public function suggestions(string $query, int $limit = 8): array
    {
        $query = trim(mb_strtolower($query));
        $key = 'pc:cache:search:'.$this->versions->current().':'.hash('sha256', $query.':'.$limit);

        return Cache::remember($key, now()->addMinutes(2), function () use ($query, $limit): array {
            $products = Product::public()
                ->where('name', 'like', $query.'%')
                ->with('images:id,product_id,path,alt_text,is_primary,processing_status')
                ->orderBy('name')
                ->limit($limit)
                ->get()
                ->map(function (Product $product): array {
                    $primary = $product->images->first(fn ($image) => $image->is_primary && $image->processing_status === 'ready');
                    $price = $product->promotional_price_millimes ?? $product->regular_price_millimes;

                    return [
                        'public_id' => $product->public_id,
                        'name' => $product->name,
                        'slug' => $product->slug,
                        'primary_image_url' => app(PublicMediaUrl::class)->forPath($primary?->path),
                        'effective_price' => [
                            'millimes' => $price,
                            'formatted' => number_format($price / 1000, 3, ',', ' ').' TND',
                        ],
                    ];
                })
                ->values()
                ->all();

            $categories = Category::query()
                ->where('is_active', true)
                ->where('name', 'like', $query.'%')
                ->orderBy('name')
                ->limit($limit)
                ->get(['public_id', 'name', 'slug'])
                ->map(fn (Category $category) => $category->only(['public_id', 'name', 'slug']))
                ->values()
                ->all();

            return compact('products', 'categories');
        });
    }
}
