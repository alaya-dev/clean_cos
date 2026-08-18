<?php

namespace App\Domain\Commerce\Models;

use Illuminate\Database\Eloquent\Model;

class OrderChangeEvent extends Model
{
    protected $table = 'order_change_events';

    public $timestamps = false;

    protected $fillable = ['order_id', 'order_public_reference', 'change_type', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
