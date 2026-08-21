<?php

namespace App\Domain\FirstDelivery\Models;

use App\Domain\FirstDelivery\Enums\FirstDeliveryPickupStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property string $public_id
 * @property string|null $provider_pickup_id
 * @property FirstDeliveryPickupStatus $status
 * @property string|null $print_url
 * @property int $shipment_count
 * @property bool $retryable
 * @property bool $print_refresh_pending
 * @property string|null $last_error
 * @property string|null $print_error
 * @property string|null $safe_message
 * @property Carbon $queued_at
 * @property Carbon|null $confirmed_at
 * @property Carbon|null $last_printed_at
 * @property Carbon|null $created_at
 * @property-read User|null $requestedBy
 * @property-read Collection<int, FirstDeliveryPickupItem> $items
 */
class FirstDeliveryPickup extends Model
{
    protected $fillable = [
        'first_delivery_configuration_id', 'provider_pickup_id', 'status', 'print_url',
        'shipment_count', 'attempt_count', 'retryable', 'print_refresh_pending',
        'last_error', 'print_error', 'safe_message', 'requested_by', 'queued_at',
        'confirmed_at', 'last_printed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => FirstDeliveryPickupStatus::class,
            'retryable' => 'boolean',
            'print_refresh_pending' => 'boolean',
            'queued_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'last_printed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $pickup) => $pickup->public_id ??= (string) Str::ulid());
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return BelongsTo<FirstDeliveryConfiguration, $this> */
    public function configuration(): BelongsTo
    {
        return $this->belongsTo(FirstDeliveryConfiguration::class, 'first_delivery_configuration_id');
    }

    /** @return BelongsTo<User, $this> */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return HasMany<FirstDeliveryPickupItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(FirstDeliveryPickupItem::class);
    }

    /** @return HasMany<FirstDeliveryPickupAttempt, $this> */
    public function attempts(): HasMany
    {
        return $this->hasMany(FirstDeliveryPickupAttempt::class);
    }
}
