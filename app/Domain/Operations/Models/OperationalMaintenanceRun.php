<?php

namespace App\Domain\Operations\Models;

use Illuminate\Database\Eloquent\Model;

class OperationalMaintenanceRun extends Model
{
    protected $fillable = ['task', 'status', 'started_at', 'finished_at', 'duration_ms', 'counts', 'error_code'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'finished_at' => 'datetime', 'counts' => 'array'];
    }
}
