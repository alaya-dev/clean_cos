<?php

namespace App\Domain\FirstDelivery\Models;

use Illuminate\Database\Eloquent\Model;

class FirstDeliveryLocality extends Model
{
    protected $primaryKey = 'locality_id';

    public $incrementing = false;

    protected $keyType = 'int';

    protected $fillable = [
        'locality_id',
        'locality_name',
        'delegation_name',
        'governorate_name',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'locality_id' => 'integer',
            'last_synced_at' => 'datetime',
        ];
    }
}
