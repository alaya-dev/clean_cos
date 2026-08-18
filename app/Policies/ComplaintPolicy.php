<?php

namespace App\Policies;

use App\Models\User;

class ComplaintPolicy
{
    public function manage(User $user): bool
    {
        return $user->is_active && in_array($user->role, ['admin', 'super_admin'], true);
    }
}
