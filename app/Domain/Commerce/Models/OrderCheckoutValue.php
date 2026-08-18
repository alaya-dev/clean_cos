<?php

namespace App\Domain\Commerce\Models;

use Illuminate\Database\Eloquent\Model;

class OrderCheckoutValue extends Model
{
    protected $fillable = ['checkout_field_id', 'field_key_snapshot', 'label_snapshot', 'type_snapshot', 'is_required_snapshot', 'value'];

    protected function casts(): array
    {
        return ['value' => 'array', 'is_required_snapshot' => 'boolean'];
    }
}
