<?php

namespace App\Domain\Commerce\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property array<string, mixed> $customer_data
 * @property array<int, array<string, mixed>> $cart_snapshot
 * @property array<string, mixed>|null $checkout_data
 * @property array<string, mixed>|null $attribution_snapshot
 * @property Carbon|null $last_activity_at
 * @property Carbon|null $converted_at
 */
class CheckoutDraft extends Model
{
    protected $fillable = ['public_token', 'customer_data', 'cart_snapshot', 'checkout_data', 'attribution_snapshot', 'promo_code', 'last_activity_at', 'converted_at', 'order_id'];

    protected function casts(): array
    {
        return ['customer_data' => 'array', 'cart_snapshot' => 'array', 'checkout_data' => 'array', 'attribution_snapshot' => 'encrypted:array', 'last_activity_at' => 'datetime', 'converted_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $draft): string => $draft->public_token ??= (string) Str::uuid());
    }

    /**
     * @param  Builder<CheckoutDraft>  $query
     * @return Builder<CheckoutDraft>
     */
    public function scopeAbandoned(Builder $query): Builder
    {
        return $query->whereNull('converted_at')->where('last_activity_at', '<=', now()->subMinutes((int) config('checkout.draft_abandonment_minutes', 15)));
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
