<?php

namespace App\Domain\Complaints\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComplaintStatusHistory extends Model
{
    public $timestamps = false;

    protected $table = 'complaint_status_history';

    protected $fillable = ['from_status', 'to_status', 'changed_by', 'created_at'];

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
