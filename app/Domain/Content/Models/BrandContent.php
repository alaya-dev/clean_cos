<?php

namespace App\Domain\Content\Models;

use Illuminate\Database\Eloquent\Model;

class BrandContent extends Model
{
    protected $fillable = ['heading', 'content', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
