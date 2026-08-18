<?php

namespace App\Domain\Catalog\Models;

use App\Domain\Catalog\Services\CatalogCacheVersion;
use App\Domain\Content\Services\HomepageCache;
use App\Support\Media\PublicMediaUrl;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * @property-read string|null $image_url
 * @property-read string $image_status
 */
class Category extends Model
{
    use SoftDeletes;

    protected $appends = ['image_url', 'image_status'];

    protected $fillable = ['public_id', 'name', 'slug', 'description', 'image_path', 'image_processing_status', 'image_width', 'image_height', 'is_active', 'sort_order', 'seo_title', 'seo_description'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
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

    /** @return HasMany<Product, $this> */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function mediaUrl(): ?string
    {
        return ($this->image_processing_status ?: ($this->image_path ? 'ready' : 'none')) === 'ready'
            ? app(PublicMediaUrl::class)->forPath($this->image_path)
            : null;
    }

    /** @return Attribute<string|null, never> */
    protected function imageUrl(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->mediaUrl());
    }

    /** @return Attribute<non-falsy-string, never> */
    protected function imageStatus(): Attribute
    {
        return Attribute::get(fn (): string => $this->image_processing_status ?: ($this->image_path ? 'ready' : 'none'));
    }
}
