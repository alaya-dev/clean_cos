<?php

namespace App\Policies;

use App\Models\User;

class BackOfficeUserPolicy
{
    public function manage(User $user): bool
    {
        return $user->is_active && $user->role === 'super_admin';
    }
}
