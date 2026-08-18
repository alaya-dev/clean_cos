<?php

namespace App\Domain\IdentityAccess\Actions;

use App\Models\User;

class RevokeUserSessionsAction
{
    public function handle(User $user): void
    {
        $user->increment('auth_version');
    }
}
