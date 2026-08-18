<?php

namespace App\Domain\Navex\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NavexShipmentAttempt extends Model
{
    protected $table = 'navex_shipment_attempts';

    public const CREATED_AT = null;

    public const UPDATED_AT = null;

    protected $fillable = [
        'operation', 'attempt_number', 'request_sent', 'http_status', 'outcome', 'error_classification',
        'safe_message', 'duration_ms', 'next_retry_at', 'attempted_at',
    ];

    protected function casts(): array
    {
        return ['request_sent' => 'boolean', 'next_retry_at' => 'datetime', 'attempted_at' => 'datetime'];
    }

    /** @return BelongsTo<NavexShipment, $this> */
    public function shipment(): BelongsTo
    {
        return $this->belongsTo(NavexShipment::class, 'navex_shipment_id');
    }
}
