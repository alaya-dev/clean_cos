<?php

namespace App\Http\Requests\Api\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreBackOfficeUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'super_admin';
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:160'], 'email' => ['required', 'email', 'max:255', 'unique:users,email'], 'role' => ['required', 'in:admin,super_admin'], 'is_active' => ['boolean'], 'password' => ['required', 'string', 'min:8', 'confirmed']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['password.min' => 'Le mot de passe doit contenir au moins 8 caractères.', 'password.confirmed' => 'La confirmation du mot de passe ne correspond pas.'];
    }
}
