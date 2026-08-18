<?php

namespace App\Domain\Catalog\Models;

use App\Domain\Catalog\Services\CatalogCacheVersion;
use App\Support\Media\PublicMediaUrl;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $product_id
 * @property string|null $path
 * @property string|null $original_path
 * @property array<int|string, string>|null $renditions
 * @property bool $is_primary
 * @property string $processing_status
 * @property-read string|null $public_url
 * @property-read array<int, string> $public_renditions
 */
class ProductImage extends Model
{
    protected $appends = ['public_url', 'public_renditions'];

    protected $hidden = ['original_path'];

    protected $fillable = ['public_id', 'product_id', 'product_variant_id', 'path', 'renditions', 'original_path', 'alt_text', 'width', 'height', 'sort_order', 'is_primary', 'processing_status'];

    protected function casts(): array
    {
        return ['renditions' => 'array', 'is_primary' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $model) => $model->public_id ??= (string) Str::ulid());
        static::saved(fn () => app(CatalogCacheVersion::class)->bump());
        static::deleted(fn () => app(CatalogCacheVersion::class)->bump());
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function mediaUrl(): ?string
    {
        return $this->processing_status === 'ready'
            ? app(PublicMediaUrl::class)->forPath($this->path)
            : null;
    }

    /** @return Attribute<string|null, never> */
    protected function publicUrl(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->mediaUrl());
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /** @return array<int, string> */
    public function mediaRenditions(): array
    {
        return collect($this->renditions ?? [])
            ->mapWithKeys(fn (string $path, int|string $width): array => [(int) $width => app(PublicMediaUrl::class)->forPath($path)])
            ->filter()
            ->all();
    }

    /** @return Attribute<array<int, string>, never> */
    protected function publicRenditions(): Attribute
    {
        return Attribute::get(fn (): array => $this->mediaRenditions());
    }
}
