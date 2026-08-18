<?php

namespace App\Domain\MetaTracking\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class MetaConfiguration extends Model
{
    protected $fillable = [
        'configuration_version', 'state', 'tracking_enabled', 'pixel_id', 'facebook_domain_verification', 'capi_access_token_encrypted',
        'test_mode', 'test_event_code', 'tested_at', 'test_outcome', 'activated_at', 'created_by', 'activated_by',
        'last_test_request_sent', 'last_test_http_status', 'last_test_events_received', 'last_test_error_code',
        'last_test_error_subcode', 'last_test_message', 'last_test_fbtrace_id', 'last_test_classification',
        'last_test_graph_api_version', 'last_test_source_url',
    ];

    protected $hidden = ['capi_access_token_encrypted', 'test_event_code'];

    protected function casts(): array
    {
        return ['tracking_enabled' => 'boolean', 'test_mode' => 'boolean', 'last_test_request_sent' => 'boolean', 'tested_at' => 'datetime', 'activated_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $configuration) => $configuration->public_id ??= (string) Str::ulid());
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
