<?php

namespace App\Domain\Audit\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use LogicException;

class AuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'public_id', 'actor_user_id', 'actor_role_snapshot', 'action', 'auditable_type', 'auditable_id',
        'request_id', 'before', 'after', 'created_at',
    ];

    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $log): void {
            $log->public_id ??= (string) Str::ulid();
            $log->created_at ??= now();
        });

        static::updating(fn (): never => throw new LogicException('Audit logs are append-only.'));
        static::deleting(fn (): never => throw new LogicException('Audit logs are append-only.'));
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
