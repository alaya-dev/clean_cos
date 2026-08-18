<?php

namespace App\Domain\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductOptionValue extends Model
{
    protected $fillable = ['value', 'parent_product_option_value_id', 'sort_order'];

    /** @return BelongsTo<ProductOptionGroup, $this> */
    public function productOptionGroup(): BelongsTo
    {
        return $this->belongsTo(ProductOptionGroup::class);
    }

    /** @return BelongsTo<ProductOptionValue, $this> */
    public function parentValue(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_product_option_value_id');
    }
}
