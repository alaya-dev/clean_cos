<?php

namespace Tests\Integration\Catalog;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductImage;
use App\Jobs\ProcessProductImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductImageProcessingTest extends TestCase
{
    use RefreshDatabase;

    public function test_media_job_has_bounded_retry_and_private_original_policy(): void
    {
        $job = new ProcessProductImage(1);
        self::assertSame(3, $job->tries);
        self::assertSame(120, $job->timeout);
        self::assertSame([10, 30, 60], $job->backoff);
        self::assertSame('media', $job->queue);
    }

    public function test_missing_private_original_is_a_safe_terminal_failure(): void
    {
        Storage::fake('local');
        $category = Category::query()->create(['name' => 'Cat', 'slug' => 'cat-'.str()->random(8), 'is_active' => true]);
        $product = Product::query()->create(['category_id' => $category->id, 'name' => 'Produit', 'slug' => 'prod-'.str()->random(8), 'regular_price_millimes' => 1000, 'stock_quantity' => 1, 'is_active' => true]);
        $image = ProductImage::query()->create(['product_id' => $product->id, 'original_path' => 'missing/original.jpg', 'processing_status' => 'pending']);
        (new ProcessProductImage($image->id))->handle();
        self::assertSame('failed', $image->fresh()->processing_status);
    }

    public function test_a_duplicate_job_keeps_an_already_processed_image_ready(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('products/ready.webp', 'processed-image');
        $category = Category::query()->create(['name' => 'Cat', 'slug' => 'cat-'.str()->random(8), 'is_active' => true]);
        $product = Product::query()->create(['category_id' => $category->id, 'name' => 'Produit', 'slug' => 'prod-'.str()->random(8), 'regular_price_millimes' => 1000, 'stock_quantity' => 1, 'is_active' => true]);
        $image = ProductImage::query()->create([
            'product_id' => $product->id,
            'path' => 'products/ready.webp',
            'processing_status' => 'ready',
        ]);

        (new ProcessProductImage($image->id))->handle();

        self::assertSame('ready', $image->fresh()->processing_status);
    }

    public function test_a_completed_rendition_recovers_from_a_duplicate_failure_state(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('products/recover.webp', 'processed-image');
        $category = Category::query()->create(['name' => 'Cat', 'slug' => 'cat-'.str()->random(8), 'is_active' => true]);
        $product = Product::query()->create(['category_id' => $category->id, 'name' => 'Produit', 'slug' => 'prod-'.str()->random(8), 'regular_price_millimes' => 1000, 'stock_quantity' => 1, 'is_active' => true]);
        $image = ProductImage::query()->create([
            'product_id' => $product->id,
            'path' => 'products/recover.webp',
            'processing_status' => 'failed',
        ]);

        (new ProcessProductImage($image->id))->handle();

        self::assertSame('ready', $image->fresh()->processing_status);
    }
}
