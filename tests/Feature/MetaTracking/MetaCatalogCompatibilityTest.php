<?php

namespace Tests\Feature\MetaTracking;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\MetaTracking\Models\MetaConfiguration;
use App\Domain\MetaTracking\Models\MetaEvent;
use App\Domain\MetaTracking\Services\MarketingConsentService;
use App\Domain\MetaTracking\Services\MetaCatalogIdentifierResolver;
use App\Domain\MetaTracking\Services\MetaCatalogImportService;
use App\Domain\MetaTracking\Services\MetaConversionsClient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MetaCatalogCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolver_always_uses_parent_product_mapping_for_selected_variants(): void
    {
        $category = Category::query()->create(['name' => 'Visage', 'slug' => 'visage']);
        $product = Product::query()->create(['category_id' => $category->id, 'name' => 'Sérum', 'slug' => 'serum', 'meta_catalog_id' => '100', 'regular_price_millimes' => 20_000, 'stock_quantity' => 2]);
        $variant = ProductVariant::query()->create(['product_id' => $product->id, 'combination_key' => '1', 'sku' => 'RED', 'stock_quantity' => 1]);

        $resolver = app(MetaCatalogIdentifierResolver::class);
        self::assertSame('100', $resolver->resolve($product)->identifier);
        self::assertSame('100', $resolver->resolve($product, $variant)->identifier);
        self::assertSame('product', $resolver->resolve($product, $variant)->source);
        self::assertNotSame((string) $product->id, $resolver->resolve($product)->identifier);
        self::assertNotSame($variant->sku, $resolver->resolve($product, $variant)->identifier);
        self::assertFalse(Schema::hasColumn('product_variants', 'meta_catalog_id'));
    }

    public function test_mapped_add_to_cart_uses_catalogue_id_and_unmapped_event_has_no_substitute_id(): void
    {
        $this->activeMetaConfiguration();
        $this->grantMarketingConsent();
        $category = Category::query()->create(['name' => 'Corps', 'slug' => 'corps']);
        $mapped = Product::query()->create(['category_id' => $category->id, 'name' => 'Huile', 'slug' => 'huile', 'meta_catalog_id' => '100', 'regular_price_millimes' => 39_900, 'stock_quantity' => 4, 'is_active' => true]);
        $variantProduct = Product::query()->create(['category_id' => $category->id, 'name' => 'Pack', 'slug' => 'pack', 'meta_catalog_id' => '115', 'regular_price_millimes' => 79_900, 'stock_quantity' => null, 'has_variants' => true, 'is_active' => true]);
        $variant = ProductVariant::query()->create(['product_id' => $variantProduct->id, 'combination_key' => '1', 'sku' => 'RED', 'stock_quantity' => 2, 'is_active' => true]);
        $unmapped = Product::query()->create(['category_id' => $category->id, 'name' => 'Baume', 'slug' => 'baume', 'regular_price_millimes' => 10_000, 'stock_quantity' => 4, 'is_active' => true]);

        $this->postJson('/api/v1/public/meta/events', ['event_name' => 'AddToCart', 'source_url' => 'http://localhost/produits/huile', 'product_public_id' => $mapped->public_id, 'quantity' => 2])->assertOk()->assertJsonPath('data.event.context.content_ids.0', '100');
        $this->assertSame('100', MetaEvent::query()->latest('id')->firstOrFail()->context_summary['content_ids'][0]);

        $this->postJson('/api/v1/public/meta/events', ['event_name' => 'AddToCart', 'source_url' => 'http://localhost/produits/pack', 'product_public_id' => $variantProduct->public_id, 'variant_public_id' => $variant->public_id, 'quantity' => 1])->assertOk()->assertJsonPath('data.event.context.content_ids.0', '115');

        $this->postJson('/api/v1/public/meta/events', ['event_name' => 'AddToCart', 'source_url' => 'http://localhost/produits/baume', 'product_public_id' => $unmapped->public_id, 'quantity' => 1])->assertOk()->assertJsonMissingPath('data.event.context.content_ids.0');
    }

    public function test_super_admin_mapping_changes_are_confirmed_and_audited(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $superAdmin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $category = Category::query()->create(['name' => 'Parfums', 'slug' => 'parfums']);
        $product = Product::query()->create(['category_id' => $category->id, 'name' => 'Brume', 'slug' => 'brume', 'meta_catalog_id' => '100', 'regular_price_millimes' => 20_000, 'stock_quantity' => 1]);

        $this->actingAs($admin)->patchJson('/api/v1/admin/products/'.$product->public_id, ['meta_catalog_id' => '101'])->assertForbidden();
        $this->actingAs($superAdmin)->patchJson('/api/v1/admin/products/'.$product->public_id, ['meta_catalog_id' => '101'])->assertStatus(422)->assertJsonPath('code', 'META_CATALOG_ID_CONFIRMATION_REQUIRED');
        $this->actingAs($superAdmin)->patchJson('/api/v1/admin/products/'.$product->public_id, ['meta_catalog_id' => '101', 'meta_catalog_id_confirmation' => true])->assertOk();
        $this->assertSame('101', $product->fresh()->meta_catalog_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'catalog.meta_catalog_id_changed']);
    }

    public function test_duplicate_product_catalogue_mapping_is_rejected(): void
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $category = Category::query()->create(['name' => 'Maquillage', 'slug' => 'maquillage']);
        Product::query()->create(['category_id' => $category->id, 'name' => 'Produit A', 'slug' => 'produit-a', 'meta_catalog_id' => '100', 'regular_price_millimes' => 10_000, 'stock_quantity' => 1]);
        $product = Product::query()->create(['category_id' => $category->id, 'name' => 'Produit B', 'slug' => 'produit-b', 'regular_price_millimes' => 10_000, 'stock_quantity' => 1]);

        $this->actingAs($superAdmin)
            ->patchJson('/api/v1/admin/products/'.$product->public_id, ['meta_catalog_id' => '100'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'META_CATALOG_ID_DUPLICATE');
    }

    public function test_catalog_import_dry_run_preserves_ids_and_detects_duplicates(): void
    {
        $category = Category::query()->create(['name' => 'Coffrets', 'slug' => 'coffrets']);
        Product::query()->create(['category_id' => $category->id, 'name' => 'Coffret', 'slug' => 'coffret', 'regular_price_millimes' => 10_000, 'stock_quantity' => 1]);
        $service = app(MetaCatalogImportService::class);
        $report = $service->dryRun(UploadedFile::fake()->createWithContent('catalog.csv', "meta_catalog_id,name,price,description\n100,Coffret,39.900,Description\n"));

        self::assertSame('100', $report['rows'][0]['meta_catalog_id']);
        self::assertSame('update', $report['rows'][0]['operation']);
        $this->expectException(ValidationException::class);
        $service->dryRun(UploadedFile::fake()->createWithContent('duplicate.csv', "meta_catalog_id,name,price,description\n100,A,10,\n100,B,12,\n"));
    }

    public function test_active_admin_can_simulate_a_catalogue_import(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $this->actingAs($admin)
            ->post('/api/v1/admin/meta/catalogue/import/dry-run', [
                'file' => UploadedFile::fake()->createWithContent('catalog.csv', "name,price,category\nSoin importé,12.500,Visage\n"),
            ])
            ->assertOk()
            ->assertJsonPath('data.rows.0.operation', 'create');
    }

    public function test_catalog_import_commit_preserves_external_id_as_string(): void
    {
        $category = Category::query()->create(['name' => 'Bien-être', 'slug' => 'bien-etre']);
        $product = Product::query()->create(['category_id' => $category->id, 'name' => 'Soin', 'slug' => 'soin', 'regular_price_millimes' => 10_000, 'stock_quantity' => 1]);

        app(MetaCatalogImportService::class)->commit([[
            'meta_catalog_id' => '115',
            'name' => $product->name,
            'price_millimes' => 39_900,
            'description' => null,
            'product_public_id' => $product->public_id,
            'operation' => 'update',
        ]]);

        $this->assertSame('115', $product->fresh()->meta_catalog_id);
    }

    public function test_catalog_import_updates_only_the_columns_present_in_a_partial_file(): void
    {
        $originalCategory = Category::query()->create(['name' => 'Visage', 'slug' => 'visage']);
        $newCategory = Category::query()->create(['name' => 'Corps', 'slug' => 'corps']);
        $product = Product::query()->create([
            'category_id' => $originalCategory->id,
            'name' => 'Crème douceur',
            'slug' => 'creme-douceur',
            'meta_catalog_id' => '100',
            'short_description' => 'Ancienne description',
            'regular_price_millimes' => 42_500,
            'stock_quantity' => 1,
        ]);

        $service = app(MetaCatalogImportService::class);
        $report = $service->dryRun(UploadedFile::fake()->createWithContent(
            'partial.csv',
            "name,description,category\nCrème douceur,Nouvelle description,Corps\n",
        ));

        self::assertSame('update', $report['rows'][0]['operation']);
        self::assertSame(['name', 'description', 'category'], $report['rows'][0]['provided_fields']);
        $service->commit($report['rows']);

        $product->refresh();
        self::assertSame('100', $product->meta_catalog_id);
        self::assertSame(42_500, $product->regular_price_millimes);
        self::assertSame('Nouvelle description', $product->short_description);
        self::assertSame($newCategory->id, $product->category_id);
    }

    public function test_catalog_import_skips_an_incomplete_new_product_without_failing_the_preview(): void
    {
        Category::query()->create(['name' => 'Visage', 'slug' => 'visage']);

        $report = app(MetaCatalogImportService::class)->dryRun(UploadedFile::fake()->createWithContent(
            'incomplete.csv',
            "name,description,category\nNouveau soin,Description,Visage\n",
        ));

        self::assertSame('skipped', $report['rows'][0]['operation']);
        self::assertSame(1, $report['summary']['skipped']);
        self::assertSame(0, $report['summary']['ready']);
    }

    public function test_catalog_import_accepts_excel_utf8_bom_headers_and_creates_unknown_categories(): void
    {
        $service = app(MetaCatalogImportService::class);
        $report = $service->dryRun(UploadedFile::fake()->createWithContent(
            'excel-export.csv',
            "\xEF\xBB\xBFname,description,price,category\nRUBBER BASE COLORS,,12.50,ONGLERIE\n",
        ));

        self::assertSame('RUBBER BASE COLORS', $report['rows'][0]['name']);
        self::assertSame('create', $report['rows'][0]['operation']);
        self::assertSame(1, $report['summary']['ready']);

        $service->commit($report['rows']);

        $category = Category::query()->where('name', 'ONGLERIE')->firstOrFail();
        $product = Product::query()->where('name', 'RUBBER BASE COLORS')->firstOrFail();
        self::assertTrue($category->is_active);
        self::assertSame($category->id, $product->category_id);
        self::assertSame(12_500, $product->regular_price_millimes);
    }

    public function test_capi_uses_the_same_catalogue_id_and_authoritative_item_price(): void
    {
        $configuration = MetaConfiguration::query()->create(['configuration_version' => 1, 'state' => 'active', 'tracking_enabled' => true, 'pixel_id' => '1234567890', 'capi_access_token_encrypted' => Crypt::encryptString('token'), 'activated_at' => now()]);
        $event = MetaEvent::query()->create(['event_name' => 'AddToCart', 'meta_configuration_id' => $configuration->id, 'event_time' => now(), 'consent_policy_version' => 1, 'marketing_consent' => true, 'source_url' => 'https://passion.test/produits/huile', 'context_summary' => ['content_ids' => ['100'], 'contents' => [['id' => '100', 'quantity' => 2, 'item_price_millimes' => 39900]], 'value_millimes' => 79800, 'currency' => 'TND'], 'payload_hash' => hash('sha256', 'catalog')]);
        Http::fake(['graph.facebook.com/*' => Http::response(['events_received' => 1], 200)]);

        self::assertTrue(app(MetaConversionsClient::class)->send($event->load('configuration'))->accepted);
        Http::assertSent(fn ($request): bool => $request->data()['data'][0]['custom_data']['content_ids'] === ['100'] && $request->data()['data'][0]['custom_data']['contents'][0]['id'] === '100' && $request->data()['data'][0]['custom_data']['contents'][0]['item_price'] === '39.900');
    }

    private function activeMetaConfiguration(): void
    {
        MetaConfiguration::query()->create(['configuration_version' => 1, 'state' => 'active', 'tracking_enabled' => true, 'pixel_id' => '1234567890', 'capi_access_token_encrypted' => Crypt::encryptString('token'), 'activated_at' => now()]);
    }

    private function grantMarketingConsent(): void
    {
        app()->instance(MarketingConsentService::class, new class extends MarketingConsentService
        {
            public function __construct() {}

            public function hasCurrentMarketingConsent(Request $request): bool
            {
                return true;
            }

            public function current(Request $request): array
            {
                return ['necessary' => true, 'marketing' => true, 'policy_version' => 1, 'decided' => true];
            }
        });
    }
}
