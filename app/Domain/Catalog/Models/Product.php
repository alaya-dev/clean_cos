<?php

namespace App\Domain\Catalog\Models;

use App\Domain\Catalog\Services\CatalogCacheVersion;
use App\Domain\Content\Services\HomepageCache;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Product extends Model
{
    use SoftDeletes;

    protected $fillable = ['public_id', 'category_id', 'sort_order', 'name', 'slug', 'meta_catalog_id', 'short_description', 'full_description', 'regular_price_millimes', 'promotional_price_millimes', 'stock_quantity', 'low_stock_threshold', 'is_active', 'has_variants', 'seo_title', 'seo_description', 'published_at', 'lock_version'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'has_variants' => 'boolean', 'published_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $model) => $model->public_id ??= (string) Str::ulid());
        static::saved(function (): void {
            app(CatalogCacheVersion::class)->bump();
            app(HomepageCache::class)->forget();
        });
        static::deleted(function (): void {
            app(CatalogCacheVersion::class)->bump();
            app(HomepageCache::class)->forget();
        });
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return HasMany<ProductImage, $this> */
    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class);
    }

    /** @return HasMany<ProductOptionGroup, $this> */
    public function optionGroups(): HasMany
    {
        return $this->hasMany(ProductOptionGroup::class)->where('is_current', true);
    }

    /** @return HasMany<ProductOptionGroup, $this> */
    public function allOptionGroups(): HasMany
    {
        return $this->hasMany(ProductOptionGroup::class);
    }

    /** @return HasMany<ProductVariant, $this> */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->where('is_current', true);
    }

    /** @return HasMany<ProductVariant, $this> */
    public function allVariants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    /**
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public function scopePublic(Builder $query): Builder
    {
        return $query->where('is_active', true)->whereHas('category', fn ($category) => $category->where('is_active', true));
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
