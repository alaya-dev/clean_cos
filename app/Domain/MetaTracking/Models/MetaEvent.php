<?php

namespace App\Domain\MetaTracking\Models;

use App\Domain\Commerce\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class MetaEvent extends Model
{
    public const EVENT_NAMES = ['PageView', 'ViewContent', 'Search', 'AddToCart', 'InitiateCheckout', 'Purchase'];

    protected $fillable = [
        'event_id', 'event_name', 'order_id', 'meta_configuration_id', 'event_time', 'consent_policy_version',
        'marketing_consent', 'is_synthetic', 'source_url', 'context_summary', 'user_data_encrypted', 'payload_hash', 'browser_state', 'capi_state',
        'retry_count', 'next_retry_at', 'dispatch_requested_at', 'last_error_classification', 'capi_delivered_at',
    ];

    protected $hidden = ['user_data_encrypted'];

    protected function casts(): array
    {
        return [
            'event_time' => 'datetime', 'marketing_consent' => 'boolean', 'is_synthetic' => 'boolean', 'context_summary' => 'array',
            'next_retry_at' => 'datetime', 'dispatch_requested_at' => 'datetime', 'capi_delivered_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $event): void {
            $event->public_id ??= (string) Str::ulid();
            $event->event_id ??= 'pc_'.(string) Str::ulid();
        });
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<MetaConfiguration, $this> */
    public function configuration(): BelongsTo
    {
        return $this->belongsTo(MetaConfiguration::class, 'meta_configuration_id');
    }

    /** @return HasMany<MetaEventAttempt, $this> */
    public function attempts(): HasMany
    {
        return $this->hasMany(MetaEventAttempt::class);
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
