<?php

namespace App\Domain\FirstDelivery\Models;

use App\Domain\Commerce\Models\Order;
use App\Domain\FirstDelivery\Enums\FirstDeliveryStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property string $public_id
 * @property int $order_id
 * @property FirstDeliveryStatus $local_status
 * @property string|null $barcode
 * @property int|null $remote_state_code
 * @property string|null $remote_state
 * @property string|null $print_url
 * @property Carbon|null $sent_at
 * @property Carbon|null $last_synced_at
 * @property Carbon|null $next_retry_at
 * @property string|null $last_error
 * @property string|null $request_snapshot_encrypted
 */
class FirstDeliveryShipment extends Model
{
    protected $appends = ['provider', 'provider_label', 'status', 'status_label'];

    protected $fillable = [
        'order_id',
        'first_delivery_configuration_id',
        'locality_id',
        'local_status',
        'barcode',
        'remote_state_code',
        'remote_state',
        'print_url',
        'sent_at',
        'last_synced_at',
        'next_retry_at',
        'attempt_count',
        'last_error',
        'request_snapshot_encrypted',
        'creation_mode',
        'cancel_requested_at',
        'cancelled_at',
    ];

    protected $hidden = ['request_snapshot_encrypted'];

    protected function casts(): array
    {
        return [
            'local_status' => FirstDeliveryStatus::class,
            'remote_state_code' => 'integer',
            'sent_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'next_retry_at' => 'datetime',
            'cancel_requested_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $shipment) => $shipment->public_id ??= (string) Str::ulid());
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function getProviderAttribute(): string
    {
        return 'first_delivery';
    }

    public function getProviderLabelAttribute(): string
    {
        return 'First Delivery';
    }

    public function getStatusAttribute(): string
    {
        return $this->local_status->value;
    }

    public function getStatusLabelAttribute(): string
    {
        return $this->local_status->label();
    }

    public function canBeCancelled(): bool
    {
        return filled($this->barcode) && $this->remote_state_code === 0 && $this->last_error === null;
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<FirstDeliveryConfiguration, $this> */
    public function configuration(): BelongsTo
    {
        return $this->belongsTo(FirstDeliveryConfiguration::class, 'first_delivery_configuration_id');
    }

    /** @return BelongsTo<FirstDeliveryLocality, $this> */
    public function locality(): BelongsTo
    {
        return $this->belongsTo(FirstDeliveryLocality::class, 'locality_id', 'locality_id');
    }

    /** @return HasMany<FirstDeliveryShipmentStatusHistory, $this> */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(FirstDeliveryShipmentStatusHistory::class);
    }

    /** @return HasMany<FirstDeliveryShipmentAttempt, $this> */
    public function attempts(): HasMany
    {
        return $this->hasMany(FirstDeliveryShipmentAttempt::class);
    }
}
