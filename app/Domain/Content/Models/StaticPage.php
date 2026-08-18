<?php

namespace App\Domain\Content\Models;

use Illuminate\Database\Eloquent\Model;

class StaticPage extends Model
{
    protected $fillable = ['title', 'slug', 'content', 'is_active', 'seo_title', 'seo_description'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function getRouteKeyName(): string
    {
        return 'key';
    }
}
