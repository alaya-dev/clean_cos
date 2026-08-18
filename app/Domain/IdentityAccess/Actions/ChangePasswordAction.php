<?php

namespace App\Domain\IdentityAccess\Actions;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class ChangePasswordAction
{
    public function handle(User $user, string $currentPassword, string $newPassword): void
    {
        if (! Hash::check($currentPassword, $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'Le mot de passe actuel est incorrect.']);
        }
        $user->force_password_change = false;
        $user->password = $newPassword;
        $user->auth_version++;
        $user->save();
    }
}
