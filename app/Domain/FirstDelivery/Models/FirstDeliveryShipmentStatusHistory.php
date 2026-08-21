<?php

namespace App\Domain\FirstDelivery\Models;

use App\Domain\FirstDelivery\Enums\FirstDeliveryStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FirstDeliveryShipmentStatusHistory extends Model
{
    protected $table = 'first_delivery_shipment_status_history';

    public const CREATED_AT = null;

    public const UPDATED_AT = null;

    protected $fillable = ['local_status', 'remote_state_code', 'remote_state', 'recorded_at'];

    protected function casts(): array
    {
        return [
            'local_status' => FirstDeliveryStatus::class,
            'remote_state_code' => 'integer',
            'recorded_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<FirstDeliveryShipment, $this> */
    public function shipment(): BelongsTo
    {
        return $this->belongsTo(FirstDeliveryShipment::class, 'first_delivery_shipment_id');
    }
}
