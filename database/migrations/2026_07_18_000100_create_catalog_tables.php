<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('name', 160);
            $table->string('slug', 190)->unique();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('seo_title', 255)->nullable();
            $table->string('seo_description', 320)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['is_active', 'sort_order']);
        });
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->string('name', 200);
            $table->string('slug', 190)->unique();
            $table->text('short_description')->nullable();
            $table->longText('full_description')->nullable();
            $table->unsignedBigInteger('regular_price_millimes');
            $table->unsignedBigInteger('promotional_price_millimes')->nullable();
            $table->unsignedInteger('stock_quantity')->nullable();
            $table->unsignedInteger('low_stock_threshold')->nullable();
            $table->boolean('is_active')->default(false);
            $table->boolean('has_variants')->default(false);
            $table->string('seo_title', 255)->nullable();
            $table->string('seo_description', 320)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['category_id', 'is_active']);
            $table->index(['is_active', 'published_at']);
            $table->index(['is_active', 'regular_price_millimes']);
            $table->index(['category_id', 'is_active', 'regular_price_millimes']);
        });
        Schema::create('product_option_groups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['product_id', 'name']);
        });
        Schema::create('product_option_values', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_option_group_id')->constrained()->cascadeOnDelete();
            $table->string('value', 120);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['product_option_group_id', 'value']);
        });
        Schema::create('product_variants', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('sku', 100)->nullable()->unique();
            $table->string('combination_key', 255);
            $table->unsignedInteger('stock_quantity');
            $table->unsignedInteger('low_stock_threshold')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['product_id', 'combination_key']);
            $table->index(['product_id', 'is_active']);
        });
        Schema::create('product_variant_values', function (Blueprint $table): void {
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_option_value_id')->constrained()->restrictOnDelete();
            $table->primary(['product_variant_id', 'product_option_value_id']);
        });
        Schema::create('product_images', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('path', 500)->nullable();
            $table->json('renditions')->nullable();
            $table->string('original_path', 500)->nullable();
            $table->string('alt_text', 255)->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_primary')->default(false);
            $table->string('processing_status', 20)->default('pending');
            $table->timestamps();
            $table->index(['product_id', 'sort_order']);
        });
        Schema::create('url_redirects', function (Blueprint $table): void {
            $table->id();
            $table->string('from_path', 255)->unique();
            $table->string('to_path', 255);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('url_redirects');
        Schema::dropIfExists('product_images');
        Schema::dropIfExists('product_variant_values');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('product_option_values');
        Schema::dropIfExists('product_option_groups');
        Schema::dropIfExists('products');
        Schema::dropIfExists('categories');
    }
};
