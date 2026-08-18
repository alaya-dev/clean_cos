<?php

namespace App\Http\Requests\Api\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return ['current_password' => ['required', 'string'], 'password' => ['required', 'string', 'min:8', 'confirmed']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'current_password.required' => 'Saisissez votre mot de passe actuel.',
            'password.required' => 'Saisissez un nouveau mot de passe.',
            'password.min' => 'Le nouveau mot de passe doit contenir au moins 8 caractères.',
            'password.confirmed' => 'La confirmation du nouveau mot de passe ne correspond pas.',
        ];
    }
}
