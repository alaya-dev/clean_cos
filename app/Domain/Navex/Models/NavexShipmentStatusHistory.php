<?php

namespace App\Domain\Navex\Models;

use App\Domain\Navex\Enums\NavexDeliveryStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NavexShipmentStatusHistory extends Model
{
    protected $table = 'navex_shipment_status_history';

    public const CREATED_AT = null;

    public const UPDATED_AT = null;

    protected $fillable = ['status', 'raw_status', 'raw_reason', 'provider_status_at', 'recorded_at'];

    protected function casts(): array
    {
        return [
            'status' => NavexDeliveryStatus::class,
            'provider_status_at' => 'datetime',
            'recorded_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<NavexShipment, $this> */
    public function shipment(): BelongsTo
    {
        return $this->belongsTo(NavexShipment::class, 'navex_shipment_id');
    }
}
