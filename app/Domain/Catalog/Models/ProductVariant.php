<?php

namespace App\Domain\Catalog\Models;

use App\Domain\Catalog\Services\CatalogCacheVersion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class ProductVariant extends Model
{
    protected $fillable = ['public_id', 'product_id', 'sku', 'combination_key', 'regular_price_millimes', 'promotional_price_millimes', 'stock_quantity', 'low_stock_threshold', 'is_active', 'is_default', 'is_current'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'is_default' => 'boolean', 'is_current' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $model) => $model->public_id ??= (string) Str::ulid());
        static::saved(function (): void {
            app(CatalogCacheVersion::class)->bump();
        });
        static::deleted(function (): void {
            app(CatalogCacheVersion::class)->bump();
        });
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsToMany<ProductOptionValue, $this> */
    public function values(): BelongsToMany
    {
        return $this->belongsToMany(ProductOptionValue::class, 'product_variant_values');
    }
}
