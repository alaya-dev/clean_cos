<?php

namespace App\Http\Controllers;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Content\Models\StaticPage;
use Illuminate\Http\Response;

class SitemapController extends Controller
{
    public function __invoke(): Response
    {
        $urls = collect([
            ['loc' => route('storefront.home'), 'lastmod' => null],
            ['loc' => route('storefront.products'), 'lastmod' => null],
        ])
            ->merge(Category::query()->where('is_active', true)->orderBy('id')->get(['slug', 'updated_at'])->map(fn (Category $category) => [
                'loc' => route('storefront.category', $category->slug),
                'lastmod' => $category->updated_at,
            ]))
            ->merge(Product::public()->orderBy('id')->get(['slug', 'updated_at'])->map(fn (Product $product) => [
                'loc' => route('storefront.product', $product->slug),
                'lastmod' => $product->updated_at,
            ]))
            ->merge(StaticPage::query()->where('is_active', true)->orderBy('id')->get(['slug', 'updated_at'])->map(fn (StaticPage $page) => [
                'loc' => route('storefront.page', $page->slug),
                'lastmod' => $page->updated_at,
            ]));

        return response(view('storefront.sitemap', compact('urls'))->render(), 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
        ]);
    }
}
