<?php

namespace App\Http\Requests\Api\Admin;

use Illuminate\Foundation\Http\FormRequest;

class SaveFirstDeliveryConfigurationRequest extends FormRequest
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
                $allowed = array_map('strtolower', (array) config('first_delivery.allowed_hosts'));
                if (($parts['scheme'] ?? null) !== 'https'
                    || ! in_array(strtolower((string) ($parts['host'] ?? '')), $allowed, true)) {
                    $fail('L’adresse First Delivery doit utiliser l’hôte HTTPS officiel.');
                }
            }],
            'first_delivery_token' => ['nullable', 'string', 'max:2048'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['first_delivery_token' => 'token First Delivery'];
    }
}
