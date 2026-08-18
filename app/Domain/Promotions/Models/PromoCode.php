<?php

namespace App\Domain\Promotions\Models;

use App\Domain\Commerce\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class PromoCode extends Model
{
    protected $fillable = [
        'code', 'discount_percentage', 'usage_limit', 'minimum_subtotal_millimes',
        'starts_at', 'ends_at', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'discount_percentage' => 'integer', 'usage_limit' => 'integer', 'usage_count' => 'integer',
            'minimum_subtotal_millimes' => 'integer', 'starts_at' => 'datetime', 'ends_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $promoCode): void {
            $promoCode->public_id ??= (string) Str::ulid();
            $promoCode->code = self::normalize($promoCode->code);
        });
        static::updating(fn (self $promoCode) => $promoCode->code = self::normalize($promoCode->code));
    }

    public static function normalize(string $code): string
    {
        return mb_strtoupper(trim($code));
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
