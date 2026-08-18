<?php

namespace Tests\Feature\Storefront;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PublicCatalogueTest extends TestCase
{
    use RefreshDatabase;

    public function test_homepage_hides_inactive_catalogue_records(): void
    {
        $active = Category::query()->create(['name' => 'Visage', 'slug' => 'visage', 'is_active' => true]);
        $inactive = Category::query()->create(['name' => 'Cachée', 'slug' => 'cachee', 'is_active' => false]);
        $this->product($active, 'Sérum visible', 'serum-visible');
        $this->product($inactive, 'Sérum caché', 'serum-cache');

        $this->get('/')
            ->assertOk()
            ->assertSee('Sérum visible')
            ->assertDontSee('Sérum caché')
            ->assertSee('Visage')
            ->assertDontSee('Cachée');
    }

    public function test_shop_filters_sorts_and_paginates_products_server_side(): void
    {
        $category = Category::query()->create(['name' => 'Visage', 'slug' => 'visage', 'is_active' => true]);
        $this->product($category, 'Zeste', 'zeste', 30_000);
        $this->product($category, 'Aube', 'aube', 10_000, 8_000);

        $this->get('/produits?promotions=1&sort=price_asc')
            ->assertOk()
            ->assertSeeInOrder(['Aube', '8,000 TND'])
            ->assertDontSee('Zeste');
    }

    public function test_storefront_uses_a_see_more_button_without_loading_the_full_catalogue(): void
    {
        $category = Category::query()->create(['name' => 'Soins', 'slug' => 'soins', 'is_active' => true]);
        foreach (range(1, 21) as $number) {
            $this->product($category, 'Soin '.$number, 'soin-'.$number);
        }

        $this->get('/produits')
            ->assertOk()
            ->assertSee('data-catalogue-grid', false)
            ->assertSee('data-catalogue-more', false)
            ->assertSee('Voir plus')
            ->assertDontSee('Voir Soin 1"', false);

        $this->get('/produits?page=2')
            ->assertOk()
            ->assertSee('Voir Soin 1"', false)
            ->assertDontSee('data-catalogue-more', false);
    }

    public function test_shop_filters_prices_in_visible_dinars_and_recovers_from_invalid_urls(): void
    {
        $category = Category::query()->create(['name' => 'Visage', 'slug' => 'visage', 'is_active' => true]);
        $this->product($category, 'Soin accessible', 'soin-accessible', 12_500);
        $this->product($category, 'Soin premium', 'soin-premium', 31_000);

        $this->get('/produits?min_price_dt=12,500&max_price_dt=20')
            ->assertOk()
            ->assertSee('Soin accessible')
            ->assertDontSee('Soin premium')
            ->assertSee('Prix minimum')
            ->assertSee('Effacer les filtres');

        $this->get('/produits?sort=invalide&min_price_dt=pas-un-prix&category=introuvable')
            ->assertOk()
            ->assertSee('Soin accessible')
            ->assertSee('Soin premium');
    }

    public function test_product_page_is_server_rendered_with_canonical_and_structured_data(): void
    {
        $category = Category::query()->create(['name' => 'Visage', 'slug' => 'visage', 'is_active' => true]);
        $this->product($category, 'Sérum éclat', 'serum-eclat', 25_000);

        $this->get('/produits/serum-eclat')
            ->assertOk()
            ->assertSee('Sérum éclat')
            ->assertSee('application/ld+json', false)
            ->assertSee('rel="canonical"', false)
            ->assertSee('property="og:image"', false)
            ->assertSee('schema.org', false);
    }

    public function test_product_page_hides_offer_markup_when_the_product_has_no_promotion(): void
    {
        $category = Category::query()->create(['name' => 'Visage', 'slug' => 'visage', 'is_active' => true]);
        $product = $this->product($category, 'Sérum sans offre', 'serum-sans-offre');

        $this->get('/produits/'.$product->slug)
            ->assertOk()
            ->assertSee('data-product-sale', false)
            ->assertSee('sale-badge is-hidden', false);

        $this->assertStringContainsString('.price-large .is-hidden{display:none}', (string) file_get_contents(resource_path('css/storefront.css')));
    }

    public function test_product_schema_uses_actual_availability_and_a_breadcrumb_list(): void
    {
        $category = Category::query()->create(['name' => 'Visage', 'slug' => 'visage', 'is_active' => true]);
        $product = Product::query()->create(['category_id' => $category->id, 'name' => 'Rituel épuisé', 'slug' => 'rituel-epuise', 'regular_price_millimes' => 25_000, 'stock_quantity' => null, 'is_active' => true, 'has_variants' => true, 'published_at' => now()]);
        $product->variants()->create(['combination_key' => 'empty', 'stock_quantity' => 0, 'is_active' => true]);

        $this->get('/produits/rituel-epuise')
            ->assertOk()
            ->assertSee('BreadcrumbList', false)
            ->assertSee('OutOfStock', false);
    }

    public function test_zero_stock_product_uses_a_clear_non_interactive_restock_state(): void
    {
        $category = Category::query()->create(['name' => 'Visage', 'slug' => 'visage', 'is_active' => true]);
        $product = Product::query()->create(['category_id' => $category->id, 'name' => 'Soin en attente', 'slug' => 'soin-en-attente', 'regular_price_millimes' => 25_000, 'stock_quantity' => 0, 'is_active' => true, 'has_variants' => false, 'published_at' => now()]);

        $this->get('/produits/'.$product->slug)
            ->assertOk()
            ->assertSee('data-stock-status', false)
            ->assertSee('Ce produit sera de nouveau disponible prochainement.')
            ->assertSee('button-stock-unavailable', false)
            ->assertSee('disabled data-add-to-cart', false);
    }

    public function test_zero_stock_variant_product_uses_the_same_restock_state_markup(): void
    {
        $category = Category::query()->create(['name' => 'Visage', 'slug' => 'visage', 'is_active' => true]);
        $product = Product::query()->create(['category_id' => $category->id, 'name' => 'Soin à options', 'slug' => 'soin-options', 'regular_price_millimes' => 25_000, 'stock_quantity' => null, 'is_active' => true, 'has_variants' => true, 'published_at' => now()]);
        $product->variants()->create(['combination_key' => 'empty', 'stock_quantity' => 0, 'is_active' => true]);

        $this->get('/produits/'.$product->slug)
            ->assertOk()
            ->assertSee('data-stock-status', false)
            ->assertSee('button-stock-unavailable', false)
            ->assertSee('data-product-variants', false);
    }

    public function test_search_is_noindex_and_sitemap_excludes_inactive_catalogue_entities(): void
    {
        $active = Category::query()->create(['name' => 'Visage', 'slug' => 'visage', 'is_active' => true]);
        $inactive = Category::query()->create(['name' => 'Cachée', 'slug' => 'cachee', 'is_active' => false]);
        $this->product($active, 'Soin public', 'soin-public');
        $this->product($inactive, 'Soin privé', 'soin-prive');

        $this->get('/recherche?q=soin')->assertOk()->assertSee('name="robots" content="noindex, follow"', false);
        $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
            ->assertSee('<?xml version="1.0" encoding="UTF-8"?>', false)
            ->assertSee('/categories/visage', false)->assertSee('/produits/soin-public', false)
            ->assertDontSee('/categories/cachee', false)->assertDontSee('/produits/soin-prive', false);
        $this->get('/robots.txt')->assertOk()->assertSee('Disallow: /admin')->assertSee('Sitemap: '.url('/sitemap.xml'));
    }

    public function test_legacy_product_slug_redirects_permanently(): void
    {
        $category = Category::query()->create(['name' => 'Visage', 'slug' => 'visage', 'is_active' => true]);
        $this->product($category, 'Sérum éclat', 'serum-eclat');
        DB::table('url_redirects')->insert(['from_path' => '/produits/ancien-serum', 'to_path' => '/produits/serum-eclat', 'created_at' => now(), 'updated_at' => now()]);

        $this->get('/produits/ancien-serum')->assertRedirect('/produits/serum-eclat')->assertStatus(301);
    }

    public function test_search_page_returns_matching_products_and_categories(): void
    {
        $category = Category::query()->create(['name' => 'Rituels du visage', 'slug' => 'rituels-visage', 'is_active' => true]);
        $this->product($category, 'Huile visage', 'huile-visage');

        $this->get('/recherche?q=visage')
            ->assertOk()
            ->assertSee('Huile visage')
            ->assertSee('Rituels du visage');
    }

    public function test_product_page_exposes_variant_selection_data(): void
    {
        $category = Category::query()->create(['name' => 'Visage', 'slug' => 'visage', 'is_active' => true]);
        $product = Product::query()->create(['category_id' => $category->id, 'name' => 'Sérum nuancé', 'slug' => 'serum-nuance', 'regular_price_millimes' => 25_000, 'stock_quantity' => null, 'is_active' => true, 'has_variants' => true, 'published_at' => now()]);
        $group = $product->optionGroups()->create(['name' => 'Format', 'sort_order' => 0]);
        $value = $group->values()->create(['value' => '30 ml', 'sort_order' => 0]);
        $variant = $product->variants()->create(['combination_key' => (string) $value->id, 'stock_quantity' => 3, 'is_active' => true]);
        $variant->values()->sync([$value->id]);
        $product->images()->create(['product_variant_id' => $variant->id, 'path' => 'products/serum-nuance-30ml.webp', 'processing_status' => 'ready', 'is_primary' => true]);

        $this->get('/produits/serum-nuance')
            ->assertOk()
            ->assertSee('Format')
            ->assertSee('30 ml')
            ->assertSee('data-product-variants', false)
            ->assertSee('image_url', false)
            ->assertSee('serum-nuance-30ml.webp', false);
    }

    public function test_variant_stock_quantity_is_not_rendered_for_storefront_customers(): void
    {
        $category = Category::query()->create(['name' => 'Visage', 'slug' => 'visage', 'is_active' => true]);
        $product = Product::query()->create([
            'category_id' => $category->id,
            'name' => 'Soin à options sans stock exposé',
            'slug' => 'soin-options-sans-stock-expose',
            'regular_price_millimes' => 25_000,
            'stock_quantity' => null,
            'is_active' => true,
            'has_variants' => true,
            'published_at' => now(),
        ]);
        $group = $product->optionGroups()->create(['name' => 'Format', 'sort_order' => 0]);
        $value = $group->values()->create(['value' => '30 ml', 'sort_order' => 0]);
        $variant = $product->variants()->create(['combination_key' => (string) $value->id, 'stock_quantity' => 17, 'is_active' => true]);
        $variant->values()->sync([$value->id]);

        $this->get('/produits/'.$product->slug)
            ->assertOk()
            ->assertSee('data-product-variants', false)
            ->assertDontSee('17 en stock')
            ->assertDontSee('17 unités');
    }

    public function test_product_gallery_exposes_an_accessible_thumbnail_rail_when_multiple_images_exist(): void
    {
        $category = Category::query()->create(['name' => 'Visage', 'slug' => 'visage', 'is_active' => true]);
        $product = $this->product($category, 'Sérum galerie', 'serum-galerie');
        $product->images()->create(['path' => 'products/serum-galerie-1.webp', 'processing_status' => 'ready', 'is_primary' => true]);
        $product->images()->create(['path' => 'products/serum-galerie-2.webp', 'processing_status' => 'ready', 'is_primary' => false]);

        $this->get('/produits/serum-galerie')
            ->assertOk()
            ->assertSee('data-gallery-thumbnails', false)
            ->assertSee('aria-label="Autres images du produit"', false);
    }

    public function test_homepage_cache_is_invalidated_when_a_product_changes(): void
    {
        $category = Category::query()->create(['name' => 'Visage', 'slug' => 'visage', 'is_active' => true]);
        $product = $this->product($category, 'Ancien nom', 'ancien-nom');
        $this->get('/')->assertSee('Ancien nom');
        $product->update(['name' => 'Nouveau nom']);

        $this->get('/')->assertSee('Nouveau nom')->assertDontSee('Ancien nom');
    }

    private function product(Category $category, string $name, string $slug, int $regularPrice = 20_000, ?int $promotionalPrice = null): Product
    {
        return Product::query()->create([
            'category_id' => $category->id,
            'name' => $name,
            'slug' => $slug,
            'regular_price_millimes' => $regularPrice,
            'promotional_price_millimes' => $promotionalPrice,
            'stock_quantity' => 4,
            'is_active' => true,
            'published_at' => now(),
        ]);
    }
}
