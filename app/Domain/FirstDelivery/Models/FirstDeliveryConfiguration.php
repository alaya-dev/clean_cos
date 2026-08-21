<?php

namespace App\Domain\FirstDelivery\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property string $public_id
 * @property string $mode
 * @property string $api_base_url
 * @property string|null $token_encrypted
 * @property Carbon|null $last_tested_at
 * @property Carbon|null $last_localities_synced_at
 */
class FirstDeliveryConfiguration extends Model
{
    public const MODES = ['disabled', 'manual', 'automatic'];

    protected $fillable = [
        'mode',
        'api_base_url',
        'token_encrypted',
        'last_tested_at',
        'last_test_status',
        'last_test_message',
        'last_localities_synced_at',
        'updated_by',
    ];

    protected $hidden = ['token_encrypted'];

    protected function casts(): array
    {
        return [
            'last_tested_at' => 'datetime',
            'last_localities_synced_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $configuration) => $configuration->public_id ??= (string) Str::ulid());
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function complete(): bool
    {
        return filled($this->token_encrypted);
    }
}
