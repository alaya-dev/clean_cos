<?php

namespace App\Domain\FirstDelivery\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FirstDeliveryShipmentAttempt extends Model
{
    protected $table = 'first_delivery_shipment_attempts';

    public const CREATED_AT = null;

    public const UPDATED_AT = null;

    protected $fillable = [
        'operation',
        'attempt_number',
        'request_sent',
        'http_status',
        'outcome',
        'error_classification',
        'safe_message',
        'duration_ms',
        'attempted_at',
    ];

    protected function casts(): array
    {
        return [
            'request_sent' => 'boolean',
            'attempted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<FirstDeliveryShipment, $this> */
    public function shipment(): BelongsTo
    {
        return $this->belongsTo(FirstDeliveryShipment::class, 'first_delivery_shipment_id');
    }
}
