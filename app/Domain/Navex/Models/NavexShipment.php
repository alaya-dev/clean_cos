<?php

namespace App\Domain\Navex\Models;

use App\Domain\Commerce\Models\Order;
use App\Domain\Navex\Enums\NavexDeliveryStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property string $public_id
 * @property int $order_id
 * @property int|null $navex_configuration_id
 * @property NavexDeliveryStatus $status
 * @property string|null $tracking_code
 * @property string|null $raw_status
 * @property string|null $raw_reason
 * @property string|null $previous_raw_status
 * @property string|null $previous_raw_reason
 * @property string|null $courier_name
 * @property string|null $courier_phone
 * @property Carbon|null $provider_status_at
 * @property Carbon|null $sent_at
 * @property Carbon|null $last_synchronized_at
 * @property Carbon|null $next_retry_at
 * @property int $attempt_count
 * @property string|null $last_error_classification
 * @property string|null $request_snapshot_encrypted
 * @property string $creation_mode
 */
class NavexShipment extends Model
{
    protected $appends = ['status_label', 'display_status_label'];

    protected $fillable = [
        'order_id', 'navex_configuration_id', 'status', 'tracking_code', 'raw_status', 'raw_reason',
        'previous_raw_status', 'previous_raw_reason', 'courier_name', 'courier_phone', 'provider_status_at',
        'sent_at', 'last_synchronized_at', 'next_retry_at', 'attempt_count', 'last_error_classification',
        'request_snapshot_encrypted', 'creation_mode', 'cancel_requested_at', 'cancelled_at',
    ];

    protected $hidden = ['request_snapshot_encrypted'];

    protected function casts(): array
    {
        return [
            'status' => NavexDeliveryStatus::class,
            'provider_status_at' => 'datetime', 'sent_at' => 'datetime', 'last_synchronized_at' => 'datetime',
            'next_retry_at' => 'datetime', 'cancel_requested_at' => 'datetime', 'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $shipment) => $shipment->public_id ??= (string) Str::ulid());
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<NavexConfiguration, $this> */
    public function configuration(): BelongsTo
    {
        return $this->belongsTo(NavexConfiguration::class, 'navex_configuration_id');
    }

    /** @return HasMany<NavexShipmentStatusHistory, $this> */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(NavexShipmentStatusHistory::class);
    }

    /** @return HasMany<NavexShipmentAttempt, $this> */
    public function attempts(): HasMany
    {
        return $this->hasMany(NavexShipmentAttempt::class);
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function getStatusLabelAttribute(): string
    {
        return $this->display_status_label;
    }

    public function getDisplayStatusLabelAttribute(): string
    {
        if ($this->raw_status !== null
            && NavexDeliveryStatus::fromProviderStatus($this->raw_status) === null
            && $this->last_error_classification === null
            && $this->last_synchronized_at !== null) {
            return 'Navex : '.$this->raw_status;
        }

        return $this->status->label();
    }

    public function canStillBeModifiedAtNavex(): bool
    {
        return $this->hasTrackingCode()
            && $this->last_error_classification === null
            && $this->last_synchronized_at !== null
            && mb_strtolower(trim((string) $this->raw_status)) === 'en attente';
    }

    public function hasTrackingCode(): bool
    {
        return filled($this->tracking_code);
    }
}
