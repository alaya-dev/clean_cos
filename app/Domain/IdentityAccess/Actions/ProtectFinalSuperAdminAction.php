<?php

namespace App\Domain\IdentityAccess\Actions;

use App\Models\User;
use Illuminate\Validation\ValidationException;

class ProtectFinalSuperAdminAction
{
    public function assertCanDeactivate(User $target): void
    {
        if ($target->role === 'super_admin' && $target->is_active && User::query()->where('role', 'super_admin')->where('is_active', true)->count() <= 1) {
            throw ValidationException::withMessages(['user' => 'LAST_SUPER_ADMIN_PROTECTED']);
        }
    }
}
