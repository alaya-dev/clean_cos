<?php

namespace App\Domain\Commerce\Models;

use App\Domain\Checkout\Models\CheckoutIdempotencyRecord;
use App\Domain\Navex\Models\NavexShipment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;
use Throwable;

class Order extends Model
{
    protected $fillable = [
        'checkout_idempotency_key', 'checkout_payload_hash', 'status', 'customer_name', 'customer_phone',
        'customer_id', 'customer_previous_order_at',
        'customer_city', 'customer_governorate', 'customer_address', 'subtotal_millimes', 'product_discount_millimes',
        'is_exchange', 'exchange_article_designation', 'exchange_article_count',
        'promo_code_discount_millimes', 'shipping_fee_millimes', 'total_millimes', 'manual_total_millimes', 'promo_code_id',
        'promo_code_snapshot', 'lock_version', 'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'archived_at' => 'datetime',
            'customer_previous_order_at' => 'datetime',
            'promo_code_snapshot' => 'array',
            'is_exchange' => 'boolean',
            'manual_total_millimes' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $order): void {
            $order->public_reference ??= (string) Str::ulid();
            $order->meta_event_id ??= 'purchase_'.$order->public_reference;
        });
        static::created(function (self $order): void {
            self::recordChange($order, 'created');
        });
        static::updated(function (self $order): void {
            self::recordChange($order, 'updated');
        });
        static::deleted(function (self $order): void {
            self::recordChange($order, 'deleted');
        });
    }

    private static function recordChange(self $order, string $changeType): void
    {
        try {
            OrderChangeEvent::query()->create([
                'order_id' => $order->getKey(),
                'order_public_reference' => $order->public_reference,
                'change_type' => $changeType,
                'created_at' => now(),
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /** @return HasMany<OrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /** @return HasMany<OrderCheckoutValue, $this> */
    public function checkoutValues(): HasMany
    {
        return $this->hasMany(OrderCheckoutValue::class);
    }

    /** @return HasOne<CheckoutIdempotencyRecord, $this> */
    public function checkoutIdempotencyRecord(): HasOne
    {
        return $this->hasOne(CheckoutIdempotencyRecord::class);
    }

    /** @return HasMany<OrderStatusHistory, $this> */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class)->orderBy('created_at')->orderBy('id');
    }

    /** @return HasMany<OrderNote, $this> */
    public function notes(): HasMany
    {
        return $this->hasMany(OrderNote::class);
    }

    /** @return HasOne<NavexShipment, $this> */
    public function navexShipment(): HasOne
    {
        return $this->hasOne(NavexShipment::class);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function getRouteKeyName(): string
    {
        return 'public_reference';
    }

    public function promoDiscountPercentage(): int
    {
        $snapshot = $this->getAttribute('promo_code_snapshot');

        return is_array($snapshot) && is_numeric($snapshot['discount_percentage'] ?? null)
            ? (int) $snapshot['discount_percentage']
            : 0;
    }
}
