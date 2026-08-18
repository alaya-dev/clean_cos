<?php

namespace App\Domain\Navex\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property string $public_id
 * @property string $mode
 * @property string $api_base_url
 * @property string|null $creation_credential_encrypted
 * @property string|null $tracking_credential_encrypted
 * @property string|null $deletion_credential_encrypted
 * @property string|null $sender_name
 * @property string|null $sender_location
 * @property string|null $sender_governorate
 * @property string $parcel_opening_option
 * @property Carbon|null $last_tested_at
 * @property string|null $last_test_status
 * @property string|null $last_test_message
 */
class NavexConfiguration extends Model
{
    public const MODES = ['disabled', 'manual', 'automatic'];

    protected $fillable = [
        'mode', 'api_base_url', 'creation_credential_encrypted', 'tracking_credential_encrypted',
        'deletion_credential_encrypted', 'sender_name', 'sender_location', 'sender_governorate',
        'parcel_opening_option', 'last_tested_at', 'last_test_status', 'last_test_message', 'updated_by',
    ];

    protected $hidden = ['creation_credential_encrypted', 'tracking_credential_encrypted', 'deletion_credential_encrypted'];

    protected function casts(): array
    {
        return ['last_tested_at' => 'datetime'];
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
        return filled($this->creation_credential_encrypted)
            && filled($this->tracking_credential_encrypted)
            && filled($this->deletion_credential_encrypted)
            && filled($this->sender_name)
            && filled($this->sender_location)
            && filled($this->sender_governorate);
    }
}
