<?php

namespace App\Domain\MetaTracking\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MetaEventAttempt extends Model
{
    public const CREATED_AT = null;

    public const UPDATED_AT = null;

    protected $fillable = [
        'meta_event_id', 'channel', 'attempt_number', 'outcome', 'request_sent', 'http_status', 'events_received',
        'error_classification', 'meta_error_code', 'meta_error_subcode', 'safe_message', 'fbtrace_id', 'graph_api_version', 'attempted_at',
    ];

    protected function casts(): array
    {
        return ['request_sent' => 'boolean', 'attempted_at' => 'datetime'];
    }

    /** @return BelongsTo<MetaEvent, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(MetaEvent::class, 'meta_event_id');
    }
}
