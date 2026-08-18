<?php

namespace App\Domain\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/** @property Carbon|null $last_order_at */
class Customer extends Model
{
    protected $fillable = ['phone', 'phone_normalized', 'name', 'governorate', 'city', 'address', 'last_order_at', 'orders_count'];

    protected function casts(): array
    {
        return ['last_order_at' => 'datetime'];
    }

    /** @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
