<?php

namespace App\Domain\FirstDelivery\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FirstDeliveryPickupAttempt extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'operation', 'attempt_number', 'request_sent', 'http_status', 'outcome',
        'error_classification', 'safe_message', 'duration_ms', 'attempted_at',
    ];

    protected function casts(): array
    {
        return ['request_sent' => 'boolean', 'attempted_at' => 'datetime'];
    }

    /** @return BelongsTo<FirstDeliveryPickup, $this> */
    public function pickup(): BelongsTo
    {
        return $this->belongsTo(FirstDeliveryPickup::class, 'first_delivery_pickup_id');
    }
}
