<?php

namespace App\Domain\Content\Services;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Content\Models\BrandContent;
use App\Domain\Content\Models\EditorialSection;
use App\Domain\Content\Models\HeroSlide;
use App\Domain\Content\Models\HomepageSection;
use App\Domain\Content\Models\ReassuranceItem;
use App\Domain\Content\Models\SocialGalleryItem;
use App\Domain\Content\Models\StaticPage;
use App\Domain\Content\Models\VisualCategoryTile;
use App\Domain\Navex\Enums\NavexDeliveryStatus;
use App\Domain\Settings\Services\StoreSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class HomepageContentService
{
    public function __construct(private readonly StoreSettings $settings) {}

    /** @return array<string, mixed> */
    public function viewModel(): array
    {
        return Cache::remember(HomepageCache::KEY, now()->addMinutes(10), function (): array {
            $productSections = HomepageSection::query()->where('is_active', true)->with(['products' => fn ($query) => $query->public()->with(['category', 'images'])])->orderBy('sort_order')->get();
            foreach ($productSections as $section) {
                if ($section->type === 'new_products') {
                    $section->setRelation('products', Product::public()->with(['category', 'images'])->latest('published_at')->limit(8)->get());
                } elseif ($section->type === 'best_sellers' && $section->products->isEmpty()) {
                    $ids = DB::table('order_items')
                        ->join('orders', 'orders.id', '=', 'order_items.order_id')
                        ->join('navex_shipments', 'navex_shipments.order_id', '=', 'orders.id')
                        ->where('navex_shipments.status', NavexDeliveryStatus::DeliveredPaid->value)
                        ->groupBy('order_items.product_id')
                        ->orderByRaw('SUM(order_items.quantity) DESC')
                        ->limit(8)
                        ->pluck('order_items.product_id');
                    $section->setRelation('products', Product::public()->with(['category', 'images'])->whereIn('id', $ids)->get()->sortBy(fn (Product $product) => array_search($product->id, $ids->all(), true))->values());
                }
            }

            return [
                'store' => $this->storeSettings(),
                'heroSlides' => HeroSlide::query()->where('is_active', true)->orderBy('sort_order')->limit((int) config('store.hero_active_limit'))->get(),
                'categories' => Category::query()->whereNull('parent_id')->where('is_active', true)->orderBy('sort_order')->get(),
                'productSections' => $productSections,
                'visualTiles' => VisualCategoryTile::query()->where('is_active', true)->whereHas('category', fn ($query) => $query->where('is_active', true))->with('category')->orderBy('sort_order')->get(),
                'editorial' => EditorialSection::query()->where('is_active', true)->with(['products' => fn ($query) => $query->public()->with(['category', 'images'])])->first(),
                'reassuranceItems' => ReassuranceItem::query()->where('is_active', true)->orderBy('sort_order')->limit((int) config('store.reassurance_limit'))->get(),
                'socialItems' => SocialGalleryItem::query()->where('is_active', true)->orderBy('sort_order')->get(),
                'brandContent' => BrandContent::query()->where('is_active', true)->first(),
                'footerPages' => StaticPage::query()->where('is_active', true)->orderBy('key')->get(['key', 'title', 'slug']),
            ];
        });
    }

    /** @return array<string, mixed> */
    private function storeSettings(): array
    {
        $payload = [];
        foreach (['phone', 'email', 'address', 'whatsapp_url', 'social_links', 'announcement_text', 'footer_statement', 'hero_autoplay_enabled'] as $key) {
            $payload[$key] = $this->settings->get('store.'.$key);
        }
        $payload['free_threshold_enabled'] = $this->settings->get('shipping.free_threshold_enabled');
        $payload['free_threshold_millimes'] = $this->settings->get('shipping.free_threshold_millimes');

        return $payload;
    }
}
