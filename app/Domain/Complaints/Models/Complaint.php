<?php

namespace App\Domain\Complaints\Models;

use App\Domain\Commerce\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Complaint extends Model
{
    use SoftDeletes;

    protected $fillable = ['order_id', 'customer_name', 'customer_phone', 'subject', 'description', 'status', 'attachment_path', 'attachment_mime', 'attachment_size', 'consent_at', 'resolved_at'];

    protected $hidden = ['attachment_path'];

    protected $appends = ['has_attachment'];

    protected function casts(): array
    {
        return ['consent_at' => 'datetime', 'resolved_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $complaint) => $complaint->public_reference ??= (string) Str::ulid());
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return HasMany<ComplaintNote, $this> */
    public function notes(): HasMany
    {
        return $this->hasMany(ComplaintNote::class)->orderBy('created_at');
    }

    /** @return HasMany<ComplaintStatusHistory, $this> */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(ComplaintStatusHistory::class)->orderBy('created_at');
    }

    public function getRouteKeyName(): string
    {
        return 'public_reference';
    }

    public function getHasAttachmentAttribute(): bool
    {
        return $this->attachment_path !== null;
    }
}
