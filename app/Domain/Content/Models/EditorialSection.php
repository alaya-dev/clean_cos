<?php

namespace App\Domain\Content\Models;

use App\Domain\Catalog\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class EditorialSection extends Model
{
    protected $fillable = ['eyebrow', 'heading', 'description', 'cta_label', 'cta_url', 'image_path', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $section) => $section->public_id ??= (string) Str::ulid());
    }

    /** @return BelongsToMany<Product, $this> */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'editorial_section_products')->withPivot('sort_order')->orderByPivot('sort_order');
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
