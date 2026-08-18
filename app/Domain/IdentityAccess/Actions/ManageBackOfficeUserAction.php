<?php

namespace App\Domain\IdentityAccess\Actions;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class ManageBackOfficeUserAction
{
    /** @param array<string, mixed> $data */
    public function create(array $data): User
    {
        return User::query()->create([...$data, 'password' => Hash::make($data['password']), 'is_active' => $data['is_active'] ?? true, 'force_password_change' => false]);
    }

    /** @param array<string, mixed> $data */
    public function assertCanChange(User $target, User $actor, array $data): void
    {
        if ($target->role === 'super_admin' && $target->id !== $actor->id) {
            throw ValidationException::withMessages(['user' => 'Un Super Admin ne peut pas modifier un autre Super Admin.']);
        }
        if ($target->id === $actor->id && array_key_exists('password', $data)) {
            throw ValidationException::withMessages(['password' => 'Utilisez votre page de mot de passe pour modifier votre propre mot de passe.']);
        }
        if ($target->id === $actor->id && (($data['is_active'] ?? true) === false || ($data['role'] ?? $target->role) !== 'super_admin')) {
            throw ValidationException::withMessages(['user' => 'Cette action vous bloquerait l’accès.']);
        }
        if ($target->role === 'super_admin' && $target->is_active && (($data['is_active'] ?? true) === false || ($data['role'] ?? $target->role) !== 'super_admin') && User::query()->where('role', 'super_admin')->where('is_active', true)->count() <= 1) {
            throw ValidationException::withMessages(['user' => 'Le dernier Super Admin doit rester actif.']);
        }
    }

    /** @param array<string, mixed> $data */
    public function update(User $target, array $data): User
    {
        if (isset($data['password'])) {
            $data['password'] = Hash::make((string) $data['password']);
        }
        unset($data['password_confirmation'], $data['force_password_change']);

        $target->fill($data)->save();
        $target->increment('auth_version');

        return $target->refresh();
    }

    public function delete(User $target, User $actor): void
    {
        if ($actor->id === $target->id) {
            throw ValidationException::withMessages(['user' => 'Vous ne pouvez pas supprimer votre propre compte.']);
        }
        if ($target->role !== 'admin') {
            throw ValidationException::withMessages(['user' => 'Seuls les comptes Administrateur peuvent être supprimés.']);
        }

        $target->tokens()->delete();
        $target->delete();
    }
}
