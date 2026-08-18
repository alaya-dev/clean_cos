<?php

namespace App\Domain\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CheckoutField extends Model
{
    protected $fillable = ['key', 'label', 'type', 'options', 'is_required', 'is_active', 'is_system', 'sort_order'];

    protected function casts(): array
    {
        return ['options' => 'array', 'is_required' => 'boolean', 'is_active' => 'boolean', 'is_system' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $field) => $field->public_id ??= (string) Str::ulid());
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
