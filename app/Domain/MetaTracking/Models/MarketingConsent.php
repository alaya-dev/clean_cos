<?php

namespace App\Domain\MetaTracking\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class MarketingConsent extends Model
{
    public const UPDATED_AT = 'updated_at';

    public const CREATED_AT = null;

    protected $fillable = [
        'policy_version',
        'necessary_consent',
        'marketing_consent',
        'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'necessary_consent' => 'boolean',
            'marketing_consent' => 'boolean',
            'decided_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $consent) => $consent->public_id ??= (string) Str::ulid());
    }
}
