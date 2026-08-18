<?php

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Actions\SaveProductEditorAction;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\InventoryMovement;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductImage;
use App\Jobs\ProcessProductImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SaveProductEditorActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_whole_editor_save_persists_gallery_variant_image_and_active_variant_state(): void
    {
        Storage::fake('local');
        Queue::fake();
        $category = Category::query()->create(['name' => 'Visage', 'slug' => 'visage', 'is_active' => true]);
        $product = Product::query()->create([
            'category_id' => $category->id,
            'name' => 'Crème',
            'slug' => 'creme',
            'regular_price_millimes' => 12_500,
            'stock_quantity' => 5,
            'is_active' => true,
            'has_variants' => false,
        ]);
        $image = $product->images()->create([
            'original_path' => 'products/existing.jpg',
            'processing_status' => 'ready',
            'is_primary' => true,
            'sort_order' => 0,
        ]);

        $product->refresh();
        $saved = app(SaveProductEditorAction::class)->handle($product, $this->payload($product, $category, [
            ['existing_public_id' => $image->public_id, 'upload_key' => null, 'alt_text' => 'Image principale', 'role' => 'primary', 'variant_index' => null],
            ['existing_public_id' => null, 'upload_key' => 'rose', 'alt_text' => 'Rose', 'role' => 'variant', 'variant_index' => 0],
        ]), [
            'rose' => UploadedFile::fake()->image('rose.jpg', 800, 800),
        ]);

        $saved->load('images.variant', 'variants.values');
        self::assertTrue($saved->has_variants);
        self::assertCount(1, $saved->variants);
        self::assertSame('CREME-ROSE', $saved->variants->first()->sku);
        self::assertTrue($saved->variants->first()->is_active);
        self::assertSame($saved->variants->first()->id, $saved->images->sortBy('sort_order')->last()->product_variant_id);
        self::assertTrue($saved->images->sortBy('sort_order')->first()->is_primary);
        self::assertSame('pending', $saved->images->sortBy('sort_order')->last()->processing_status);
        Queue::assertPushed(ProcessProductImage::class, fn (ProcessProductImage $job) => $job->imageId === $saved->images->sortBy('sort_order')->last()->id);

        $reopened = Product::query()->with('images.variant', 'variants.values')->findOrFail($saved->id);
        self::assertSame(
            $saved->images->sortBy('sort_order')->pluck('public_id')->values()->all(),
            $reopened->images->sortBy('sort_order')->pluck('public_id')->values()->all(),
        );
        self::assertTrue($reopened->variants->first()->is_active);

        $variantPublicId = $reopened->variants->first()->public_id;
        $repeatPayload = $this->payload($reopened, $category, [
            ['existing_public_id' => $reopened->images->sortBy('sort_order')->first()->public_id, 'upload_key' => null, 'alt_text' => 'Image principale', 'role' => 'primary', 'variant_index' => null],
            ['existing_public_id' => $reopened->images->sortBy('sort_order')->last()->public_id, 'upload_key' => null, 'alt_text' => 'Rose', 'role' => 'variant', 'variant_index' => 0],
        ]);
        $repeatPayload['name'] = 'Crème enrichie';
        $repeatPayload['variants'][0]['public_id'] = $variantPublicId;
        $savedAgain = app(SaveProductEditorAction::class)->handle($reopened, $repeatPayload, []);

        self::assertSame($variantPublicId, $savedAgain->variants->first()->public_id);
        self::assertSame('CREME-ROSE', $savedAgain->variants->first()->sku);
        self::assertTrue($savedAgain->variants->first()->is_active);
    }

    public function test_failed_editor_save_rolls_back_product_and_gallery_changes(): void
    {
        Storage::fake('local');
        $category = Category::query()->create(['name' => 'Corps', 'slug' => 'corps', 'is_active' => true]);
        $product = Product::query()->create([
            'category_id' => $category->id,
            'name' => 'Huile',
            'slug' => 'huile',
            'regular_price_millimes' => 10_000,
            'stock_quantity' => 3,
            'is_active' => true,
            'has_variants' => false,
        ]);
        $image = $product->images()->create([
            'original_path' => 'products/huile.jpg',
            'processing_status' => 'ready',
            'is_primary' => true,
            'sort_order' => 0,
        ]);
        $payload = $this->payload($product, $category, [
            ['existing_public_id' => $image->public_id, 'upload_key' => null, 'alt_text' => null, 'role' => 'primary', 'variant_index' => null],
            ['existing_public_id' => null, 'upload_key' => 'duplicate', 'alt_text' => null, 'role' => 'primary', 'variant_index' => null],
        ]);
        $payload['name'] = 'Huile modifiée';

        try {
            app(SaveProductEditorAction::class)->handle($product, $payload, [
                'duplicate' => UploadedFile::fake()->image('duplicate.jpg'),
            ]);
            self::fail('La sauvegarde devait être rejetée.');
        } catch (ValidationException) {
            // Expected: the gallery cannot have two primary images.
        }

        self::assertSame('Huile', $product->fresh()->name);
        self::assertSame(1, ProductImage::query()->where('product_id', $product->id)->count());
    }

    public function test_variant_product_can_be_converted_to_a_simple_product_without_deleting_history_or_gallery(): void
    {
        $category = Category::query()->create(['name' => 'Cheveux', 'slug' => 'cheveux', 'is_active' => true]);
        $product = Product::query()->create([
            'category_id' => $category->id,
            'name' => 'Masque',
            'slug' => 'masque',
            'regular_price_millimes' => 16_000,
            'stock_quantity' => null,
            'is_active' => true,
            'has_variants' => true,
        ]);
        $group = $product->optionGroups()->create(['name' => 'Format', 'sort_order' => 0]);
        $value = $group->values()->create(['value' => '250 ml', 'sort_order' => 0]);
        $variant = $product->variants()->create(['combination_key' => (string) $value->id, 'stock_quantity' => 4, 'is_active' => true]);
        $variant->values()->sync([$value->id]);
        $image = $product->images()->create([
            'original_path' => 'products/masque.jpg',
            'processing_status' => 'ready',
            'is_primary' => true,
            'product_variant_id' => $variant->id,
        ]);
        InventoryMovement::query()->create([
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'type' => 'manual_adjustment',
            'quantity_delta' => 4,
            'quantity_before' => 0,
            'quantity_after' => 4,
            'reason' => 'Stock initial',
        ]);

        $product->refresh();
        $saved = app(SaveProductEditorAction::class)->handle($product, $this->simplePayload($product, $category, 13), []);

        self::assertFalse($saved->has_variants);
        self::assertSame(13, $saved->stock_quantity);
        self::assertSame(0, $saved->variants()->count());
        self::assertSame(1, $saved->allVariants()->count());
        self::assertFalse($saved->allVariants()->firstOrFail()->is_active);
        self::assertFalse($saved->allVariants()->firstOrFail()->is_current);
        self::assertDatabaseHas('inventory_movements', ['product_variant_id' => $variant->id]);
        self::assertSame($image->id, $saved->images()->firstOrFail()->id);
        self::assertNull($saved->images()->firstOrFail()->product_variant_id);
        self::assertSame(0, $saved->optionGroups()->count());
    }

    public function test_replacing_variant_structure_archives_referenced_variants_without_losing_inventory_history(): void
    {
        $category = Category::query()->create(['name' => 'Parfums', 'slug' => 'parfums', 'is_active' => true]);
        $product = Product::query()->create([
            'category_id' => $category->id,
            'name' => 'Brume',
            'slug' => 'brume',
            'regular_price_millimes' => 18_000,
            'stock_quantity' => null,
            'is_active' => true,
            'has_variants' => true,
        ]);
        $oldGroup = $product->optionGroups()->create(['name' => 'Ancien format']);
        $oldValue = $oldGroup->values()->create(['value' => '50 ml']);
        $oldVariant = $product->variants()->create([
            'combination_key' => (string) $oldValue->id,
            'stock_quantity' => 2,
            'is_active' => true,
        ]);
        $oldVariant->values()->sync([$oldValue->id]);
        InventoryMovement::query()->create([
            'product_id' => $product->id,
            'product_variant_id' => $oldVariant->id,
            'type' => 'manual_adjustment',
            'quantity_delta' => 2,
            'quantity_before' => 0,
            'quantity_after' => 2,
            'reason' => 'Stock initial',
        ]);

        $product = $product->fresh();
        $saved = app(SaveProductEditorAction::class)->handle($product, $this->payload($product, $category, []), []);

        self::assertSame(1, $saved->variants()->count());
        self::assertSame(2, $saved->allVariants()->count());
        self::assertFalse($oldVariant->fresh()->is_current);
        self::assertFalse($oldVariant->fresh()->is_active);
        self::assertFalse($oldGroup->fresh()->is_current);
        self::assertDatabaseHas('inventory_movements', ['product_variant_id' => $oldVariant->id]);
    }

    public function test_gallery_is_optional_and_an_omitted_gallery_preserves_existing_images(): void
    {
        $category = Category::query()->create(['name' => 'Corps', 'slug' => 'corps', 'is_active' => true]);
        $product = Product::query()->create([
            'category_id' => $category->id,
            'name' => 'Lait',
            'slug' => 'lait',
            'regular_price_millimes' => 9_000,
            'stock_quantity' => 2,
            'is_active' => true,
            'has_variants' => false,
        ]);

        $product->refresh();
        $saved = app(SaveProductEditorAction::class)->handle($product, $this->simplePayload($product, $category, 2), []);
        self::assertSame(0, $saved->images()->count());

        $image = $saved->images()->create(['original_path' => 'products/lait.jpg', 'processing_status' => 'ready', 'is_primary' => true]);
        $saved->refresh();
        $updated = app(SaveProductEditorAction::class)->handle($saved, $this->simplePayload($saved, $category, 5), []);

        self::assertSame(5, $updated->stock_quantity);
        self::assertSame($image->id, $updated->images()->firstOrFail()->id);
    }

    /** @param array<int, array<string, mixed>> $gallery
     * @return array<string, mixed>
     */
    private function payload(Product $product, Category $category, array $gallery): array
    {
        return [
            'lock_version' => $product->lock_version,
            'category_public_id' => $category->public_id,
            'name' => $product->name,
            'slug' => $product->slug,
            'meta_catalog_id' => null,
            'short_description' => null,
            'full_description' => null,
            'regular_price_millimes' => 12_500,
            'promotional_price_millimes' => null,
            'stock_quantity' => null,
            'low_stock_threshold' => null,
            'is_active' => true,
            'has_variants' => true,
            'seo_title' => null,
            'seo_description' => null,
            'option_groups' => [[
                'name' => 'Couleur',
                'values' => [['client_key' => 'colour:rose', 'value' => 'Rose']],
            ]],
            'variants' => [[
                'option_value_client_keys' => ['colour:rose'],
                'sku' => 'CREME-ROSE',
                'stock_quantity' => 7,
                'low_stock_threshold' => 2,
                'is_active' => true,
            ]],
            'gallery' => $gallery,
        ];
    }

    /** @return array<string, mixed> */
    private function simplePayload(Product $product, Category $category, int $stockQuantity): array
    {
        return [
            'lock_version' => $product->lock_version,
            'category_public_id' => $category->public_id,
            'name' => $product->name,
            'slug' => $product->slug,
            'meta_catalog_id' => null,
            'short_description' => null,
            'full_description' => null,
            'regular_price_millimes' => $product->regular_price_millimes,
            'promotional_price_millimes' => null,
            'stock_quantity' => $stockQuantity,
            'low_stock_threshold' => 2,
            'is_active' => true,
            'has_variants' => false,
            'seo_title' => null,
            'seo_description' => null,
            'option_groups' => [],
            'variants' => [],
        ];
    }
}
