<?php

namespace App\Domain\Complaints\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComplaintNote extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_id', 'body', 'created_at'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
