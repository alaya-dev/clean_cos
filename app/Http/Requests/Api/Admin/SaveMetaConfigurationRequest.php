<?php

namespace App\Http\Requests\Api\Admin;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class SaveMetaConfigurationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->role === 'super_admin' && $user->is_active;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'mode' => ['required', 'in:disabled,test,live'],
            'pixel_id' => ['nullable', 'regex:/^\d{5,30}$/'],
            'facebook_domain_verification' => ['nullable', 'regex:/^[A-Za-z0-9_-]{8,255}$/'],
            'capi_access_token' => ['nullable', 'string', 'max:1000'],
            'test_event_code' => ['nullable', 'string', 'max:120'],
            'base_configuration_public_id' => ['nullable', 'ulid', 'exists:meta_configurations,public_id'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $verification = $this->input('facebook_domain_verification');
        if (is_string($verification)) {
            $verification = trim($verification);
            if (str_starts_with(strtolower($verification), '<meta')) {
                $hasExpectedName = preg_match('/\\bname\\s*=\\s*(["\\\'])facebook-domain-verification\\1/i', $verification) === 1;
                $contentMatched = preg_match('/\\bcontent\\s*=\\s*(["\\\'])([^"\\\']+)\\1/i', $verification, $content);
                $verification = $hasExpectedName && $contentMatched === 1 ? trim($content[2]) : $verification;
            }
            $this->merge(['facebook_domain_verification' => $verification !== '' ? $verification : null]);
        }
        if (! $this->has('mode') && $this->has('tracking_enabled')) {
            $this->merge(['mode' => ! $this->boolean('tracking_enabled') ? 'disabled' : ($this->boolean('test_mode') ? 'test' : 'live')]);
        }
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'mode.required' => 'Choisissez le mode Désactivé, Test ou Production.',
            'pixel_id.regex' => 'L’identifiant Pixel doit contenir uniquement 5 à 30 chiffres.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'capi_access_token' => 'jeton CAPI',
            'test_event_code' => 'code d’événement de test',
            'pixel_id' => 'identifiant Pixel',
        ];
    }
}
