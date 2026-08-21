<?php

namespace App\Domain\FirstDelivery\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $first_delivery_pickup_id
 * @property int|null $first_delivery_shipment_id
 * @property string $barcode
 * @property string $order_reference
 * @property-read FirstDeliveryShipment|null $shipment
 */
class FirstDeliveryPickupItem extends Model
{
    public $timestamps = false;

    protected $fillable = ['first_delivery_shipment_id', 'barcode', 'order_reference', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    /** @return BelongsTo<FirstDeliveryPickup, $this> */
    public function pickup(): BelongsTo
    {
        return $this->belongsTo(FirstDeliveryPickup::class, 'first_delivery_pickup_id');
    }

    /** @return BelongsTo<FirstDeliveryShipment, $this> */
    public function shipment(): BelongsTo
    {
        return $this->belongsTo(FirstDeliveryShipment::class, 'first_delivery_shipment_id');
    }
}
