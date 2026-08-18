<?php

namespace Tests\Feature\StoreManagement;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Content\Models\BrandContent;
use App\Domain\Content\Models\HeroSlide;
use App\Domain\Content\Models\HomepageSection;
use App\Domain\Content\Models\StaticPage;
use App\Domain\Content\Services\HomepageCache;
use App\Domain\Settings\Services\StoreSettings;
use App\Models\User;
use App\Support\Content\RichTextSanitizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ContentStaticPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_homepage_is_server_rendered_in_managed_mobile_first_order_and_hides_inactive_content(): void
    {
        Storage::fake('public');
        $active = Category::query()->create(['name' => 'Visage', 'slug' => 'visage', 'is_active' => true, 'sort_order' => 0, 'image_path' => 'categories/visage.webp']);
        Category::query()->create(['name' => 'Catégorie masquée', 'slug' => 'cachee', 'is_active' => false, 'sort_order' => 1]);
        $product = Product::query()->create(['category_id' => $active->id, 'name' => 'Sérum géré', 'slug' => 'serum-gere', 'regular_price_millimes' => 30_000, 'stock_quantity' => 5, 'is_active' => true, 'has_variants' => false, 'published_at' => now()]);
        $section = HomepageSection::query()->where('type', 'best_sellers')->firstOrFail();
        $section->products()->sync([$product->id => ['sort_order' => 0]]);
        HeroSlide::query()->create(['admin_label' => 'Première', 'heading' => 'Beauté intentionnelle', 'desktop_image_path' => 'heroes/first.webp', 'mobile_image_path' => 'heroes/mobile.webp', 'is_active' => true, 'sort_order' => 0]);
        HeroSlide::query()->create(['admin_label' => 'Seconde', 'heading' => 'Rituels choisis', 'desktop_image_path' => 'heroes/second.webp', 'is_active' => true, 'sort_order' => 1]);
        HeroSlide::query()->create(['admin_label' => 'Masquée', 'heading' => 'Ne pas afficher', 'desktop_image_path' => 'heroes/hidden.webp', 'is_active' => false, 'sort_order' => 2]);
        BrandContent::query()->create(['heading' => 'La marque Passion', 'content' => '<p>Une approche attentive.</p>', 'is_active' => true]);
        app(HomepageCache::class)->forget();

        $response = $this->get('/')->assertOk()->assertSee('Beauté intentionnelle')->assertDontSee('Ne pas afficher')->assertSee('Visage')->assertDontSee('Catégorie masquée')->assertSee('Sérum géré')->assertSee('La marque Passion');
        $response->assertSeeInOrder(['home-hero', 'category-explorer', 'bestsellers-intro', 'featured-products', 'store-footer'], false);
        $response->assertSee('fetchpriority=high loading=eager', false)->assertSee('data-hero-prev', false)->assertSee('data-hero-next', false)->assertSee('data-hero-dot', false);
        $response->assertSee('circular-category-rail', false)->assertSee('product-grid', false);
    }

    public function test_announcement_ticker_repeats_its_managed_message_eight_times_for_a_seamless_loop(): void
    {
        $actor = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        app(StoreSettings::class)->update(['store.announcement_text' => 'Annonce de test continue'], $actor->id);
        app(HomepageCache::class)->forget();

        $content = $this->get('/')->assertOk()->getContent();

        $this->assertSame(9, substr_count($content, 'Annonce de test continue'));
    }

    public function test_content_reordering_invalidates_cache_writes_audit_and_is_super_admin_only(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $superAdmin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $sections = HomepageSection::query()->orderBy('sort_order')->get();
        Cache::put(HomepageCache::KEY, ['stale' => true], 60);

        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/content/homepage-sections')->assertForbidden();
        $this->actingAs($superAdmin, 'sanctum')->postJson('/api/v1/admin/content/homepage-sections/reorder', ['items' => $sections->map(fn ($section, $index) => ['public_id' => $section->public_id, 'sort_order' => $sections->count() - $index])->all()])->assertOk();

        $this->assertFalse(Cache::has(HomepageCache::KEY));
        $this->assertDatabaseHas('audit_logs', ['action' => 'content.homepage_sections_reordered']);
    }

    public function test_curated_section_can_be_hidden_and_deleted_by_a_super_admin(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $superAdmin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $section = HomepageSection::query()->firstOrCreate(
            ['type' => 'curated', 'title' => 'Sélection à masquer'],
            ['eyebrow' => null, 'description' => null, 'is_active' => true, 'filters_enabled' => false, 'sort_order' => 99],
        );
        Cache::put(HomepageCache::KEY, ['stale' => true], 60);

        $this->actingAs($superAdmin, 'sanctum')->patchJson('/api/v1/admin/content/homepage-sections/'.$section->public_id, [
            'type' => 'curated',
            'eyebrow' => $section->eyebrow,
            'title' => $section->title,
            'description' => $section->description,
            'is_active' => false,
            'filters_enabled' => $section->filters_enabled,
            'sort_order' => $section->sort_order,
            'product_public_ids' => $section->products()->pluck('products.public_id')->all(),
        ])->assertOk()->assertJsonPath('data.is_active', false);

        $this->assertFalse(Cache::has(HomepageCache::KEY));
        $this->assertDatabaseHas('audit_logs', ['action' => 'content.homepage_section_updated']);

        $this->actingAs($admin, 'sanctum')->deleteJson('/api/v1/admin/content/homepage-sections/'.$section->public_id)
            ->assertForbidden();

        $this->actingAs($superAdmin, 'sanctum')->deleteJson('/api/v1/admin/content/homepage-sections/'.$section->public_id)
            ->assertOk();

        $this->assertDatabaseMissing('homepage_sections', ['id' => $section->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'content.homepage_section_deleted']);
    }

    public function test_hero_slide_can_be_deleted_by_a_super_admin(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $superAdmin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $hero = HeroSlide::query()->create([
            'admin_label' => 'Promotion estivale',
            'heading' => 'Une offre douce',
            'desktop_image_path' => 'heroes/promotion.webp',
            'mobile_image_path' => 'heroes/promotion-mobile.webp',
            'is_active' => true,
            'sort_order' => 0,
        ]);
        Storage::disk('public')->put($hero->desktop_image_path, 'desktop');
        Storage::disk('public')->put($hero->mobile_image_path, 'mobile');

        $this->actingAs($admin, 'sanctum')->deleteJson('/api/v1/admin/content/banners/'.$hero->public_id)
            ->assertForbidden();

        $this->actingAs($superAdmin, 'sanctum')->deleteJson('/api/v1/admin/content/banners/'.$hero->public_id)
            ->assertOk();

        $this->assertDatabaseMissing('hero_slides', ['id' => $hero->id]);
        Storage::disk('public')->assertMissing('heroes/promotion.webp');
        Storage::disk('public')->assertMissing('heroes/promotion-mobile.webp');
        $this->assertDatabaseHas('audit_logs', ['action' => 'content.hero_deleted']);
    }

    public function test_curated_product_order_is_preserved_and_inactive_products_are_omitted(): void
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        HomepageSection::query()->update(['is_active' => false]);
        $category = Category::query()->create(['name' => 'Rituels', 'slug' => 'rituels', 'is_active' => true]);
        $first = Product::query()->create(['category_id' => $category->id, 'name' => 'Premier actif', 'slug' => 'premier-actif', 'regular_price_millimes' => 10_000, 'stock_quantity' => 2, 'is_active' => true, 'published_at' => now()]);
        $second = Product::query()->create(['category_id' => $category->id, 'name' => 'Second actif', 'slug' => 'second-actif', 'regular_price_millimes' => 11_000, 'stock_quantity' => 2, 'is_active' => true, 'published_at' => now()]);
        $inactive = Product::query()->create(['category_id' => $category->id, 'name' => 'Produit inactif', 'slug' => 'produit-inactif', 'regular_price_millimes' => 12_000, 'stock_quantity' => 2, 'is_active' => false, 'published_at' => now()]);

        $this->actingAs($superAdmin, 'sanctum')->postJson('/api/v1/admin/content/homepage-sections', [
            'type' => 'custom', 'eyebrow' => 'Choix', 'title' => 'Sélection ordonnée', 'description' => null,
            'is_active' => true, 'filters_enabled' => false, 'sort_order' => 0,
            'product_public_ids' => [$second->public_id, $inactive->public_id, $first->public_id],
        ])->assertCreated()->assertJsonPath('data.products.0.public_id', $second->public_id);

        app(HomepageCache::class)->forget();
        $this->get('/')->assertOk()->assertSeeInOrder(['Second actif', 'Premier actif'])->assertDontSee('Produit inactif');
    }

    public function test_hero_limit_safe_urls_and_secure_image_processing_are_enforced(): void
    {
        Storage::fake('public');
        $superAdmin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        foreach (range(1, (int) config('store.hero_active_limit')) as $index) {
            HeroSlide::query()->create(['admin_label' => 'Slide '.$index, 'heading' => 'Titre '.$index, 'desktop_image_path' => 'heroes/'.$index.'.webp', 'is_active' => true, 'sort_order' => $index]);
        }
        $payload = ['admin_label' => 'En trop', 'heading' => 'En trop', 'cta_url' => 'https://example.com/autorise', 'desktop_image' => UploadedFile::fake()->image('hero.jpg', 1200, 800), 'is_active' => true, 'sort_order' => 9];
        $this->actingAs($superAdmin, 'sanctum')->post('/api/v1/admin/content/banners', $payload, ['Accept' => 'application/json'])->assertUnprocessable();

        HeroSlide::query()->where('is_active', true)->firstOrFail()->update(['is_active' => false]);
        $payload['cta_url'] = 'http://insecure.example/';
        $this->actingAs($superAdmin, 'sanctum')->post('/api/v1/admin/content/banners', $payload, ['Accept' => 'application/json'])->assertUnprocessable();
        $payload['cta_url'] = '/produits';
        $created = $this->actingAs($superAdmin, 'sanctum')->post('/api/v1/admin/content/banners', $payload, ['Accept' => 'application/json'])->assertCreated();
        $path = $created->json('data.desktop_image_path');
        Storage::disk('public')->assertExists($path);
        $this->assertSame('image/webp', Storage::disk('public')->mimeType($path));
        $this->assertDatabaseHas('audit_logs', ['action' => 'content.hero_created']);
    }

    public function test_category_image_upload_is_available_on_creation_and_edit_flow(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $category = Category::query()->create(['name' => 'Corps', 'slug' => 'corps', 'is_active' => true]);
        $response = $this->actingAs($admin, 'sanctum')->post('/api/v1/admin/categories/'.$category->public_id.'/image', ['image' => UploadedFile::fake()->image('category.png', 700, 700)], ['Accept' => 'application/json'])->assertOk();

        $this->assertNotNull($response->json('data.image_url'));
        $this->assertSame('ready', $category->fresh()->image_processing_status);
        Storage::disk('public')->assertExists($category->fresh()->image_path);
    }

    public function test_store_social_urls_and_tunisian_whatsapp_numbers_are_normalized_safely(): void
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $this->actingAs($superAdmin, 'sanctum')->patchJson('/api/v1/admin/settings/store', [
            'phone' => null, 'email' => null, 'address' => null, 'whatsapp_url' => '+216 12 345 678',
            'social_links' => ['instagram' => 'https://www.instagram.com/passion/', 'facebook' => 'https://www.facebook.com/passion'],
            'announcement_text' => null, 'footer_statement' => null, 'hero_autoplay_enabled' => true,
        ])->assertOk()->assertJsonPath('data.whatsapp_url', 'https://wa.me/21612345678')->assertJsonPath('data.social_links.instagram', 'https://www.instagram.com/passion')->assertJsonPath('data.social_links.facebook', 'https://www.facebook.com/passion');

        $this->actingAs($superAdmin, 'sanctum')->patchJson('/api/v1/admin/settings/store', [
            'whatsapp_url' => 'https://user:secret@wa.me/21612345678', 'social_links' => ['instagram' => 'https://user:secret@www.instagram.com/passion'], 'hero_autoplay_enabled' => true,
        ])->assertUnprocessable()->assertJsonMissingPath('errors.whatsapp_url.0.validation');
    }

    public function test_rich_text_is_strictly_sanitized(): void
    {
        $html = '<h2 style="color:red">Titre</h2><script>alert(1)</script><iframe src="https://evil.test"></iframe><p onclick="x()">Texte <a href="javascript:alert(1)" style="x">lien</a></p><a href="/produits">interne</a>';
        $clean = app(RichTextSanitizer::class)->sanitize($html);

        $this->assertStringContainsString('<h2>Titre</h2>', $clean);
        $this->assertStringContainsString('href="/produits"', $clean);
        $this->assertStringNotContainsString('script', $clean);
        $this->assertStringNotContainsString('iframe', $clean);
        $this->assertStringNotContainsString('javascript:', $clean);
        $this->assertStringNotContainsString('style=', $clean);
        $this->assertStringNotContainsString('onclick=', $clean);
    }

    public function test_static_pages_support_sanitization_metadata_visibility_and_slug_redirects(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $superAdmin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $page = StaticPage::query()->where('key', 'privacy')->firstOrFail();
        $this->actingAs($admin, 'sanctum')->patchJson('/api/v1/admin/content/pages/privacy', ['is_active' => true])->assertForbidden();
        $this->get('/pages/'.$page->slug)->assertNotFound();

        $this->actingAs($superAdmin, 'sanctum')->patchJson('/api/v1/admin/content/pages/privacy', [
            'title' => 'Confidentialité', 'slug' => 'vie-privee', 'content' => '<p>Texte <script>secret</script><strong>légal</strong></p>',
            'seo_title' => 'Vie privée Passion', 'seo_description' => 'Politique de confidentialité.', 'is_active' => true,
        ])->assertOk();
        $this->actingAs($superAdmin, 'sanctum')->patchJson('/api/v1/admin/content/pages/terms', ['slug' => 'vie-privee'])->assertUnprocessable();

        $this->get('/pages/confidentialite')->assertStatus(301)->assertRedirect('/pages/vie-privee');
        $this->get('/pages/vie-privee')->assertOk()->assertSee('Vie privée Passion')->assertSee('rel="canonical"', false)->assertSee('property="og:title"', false)->assertSee('Dernière mise à jour')->assertDontSee('secret');
        $this->assertDatabaseHas('audit_logs', ['action' => 'content.static_page_updated']);
    }
}
