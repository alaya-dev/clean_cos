<?php

namespace App\Domain\Content\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class HeroSlide extends Model
{
    protected $fillable = ['admin_label', 'eyebrow', 'heading', 'supporting_text', 'cta_label', 'cta_url', 'desktop_image_path', 'mobile_image_path', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $slide) => $slide->public_id ??= (string) Str::ulid());
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
