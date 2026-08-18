<?php

namespace App\Http\Requests\Api\Admin;

use Illuminate\Foundation\Http\FormRequest;

class SaveNavexConfigurationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('store.manage') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'mode' => ['required', 'in:disabled,manual,automatic'],
            'api_base_url' => ['required', 'url', 'max:255', function (string $attribute, mixed $value, \Closure $fail): void {
                $parts = parse_url((string) $value);
                $allowed = array_map('strtolower', (array) config('navex.allowed_hosts'));
                if (($parts['scheme'] ?? null) !== 'https' || ! in_array(strtolower((string) ($parts['host'] ?? '')), $allowed, true)) {
                    $fail('L’adresse Navex doit utiliser un hôte HTTPS autorisé.');
                }
            }],
            'creation_credential' => ['nullable', 'string', 'max:255'],
            'tracking_credential' => ['nullable', 'string', 'max:255'],
            'deletion_credential' => ['nullable', 'string', 'max:255'],
            'sender_name' => ['nullable', 'string', 'max:180'],
            'sender_location' => ['nullable', 'string', 'max:300'],
            'sender_governorate' => ['nullable', 'string', 'max:80'],
            // Kept optional for backwards-compatible clients; new shipments always force Oui centrally.
            'parcel_opening_option' => ['sometimes', 'in:Oui,Non,Uniquement pour les marketplaces'],
        ];
    }
}
