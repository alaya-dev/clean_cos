<?php

namespace App\Domain\Content\Models;

use App\Domain\Catalog\Models\Category;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class VisualCategoryTile extends Model
{
    protected $fillable = ['category_id', 'label', 'desktop_image_path', 'mobile_image_path', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $tile) => $tile->public_id ??= (string) Str::ulid());
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
