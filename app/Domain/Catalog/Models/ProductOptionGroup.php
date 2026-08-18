<?php

namespace App\Domain\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductOptionGroup extends Model
{
    protected $fillable = ['name', 'sort_order', 'is_current'];

    protected function casts(): array
    {
        return ['is_current' => 'boolean'];
    }

    /** @return HasMany<ProductOptionValue, $this> */
    public function values(): HasMany
    {
        return $this->hasMany(ProductOptionValue::class);
    }
}
