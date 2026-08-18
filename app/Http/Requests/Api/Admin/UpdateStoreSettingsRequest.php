<?php

namespace App\Http\Requests\Api\Admin;

use App\Support\Content\SafeUrl;
use Illuminate\Foundation\Http\FormRequest;

class UpdateStoreSettingsRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $whatsApp = $this->input('whatsapp_url');
        if (is_string($whatsApp)) {
            $digits = preg_replace('/\D+/', '', $whatsApp) ?? '';
            if (preg_match('/^(?:216)?(\d{8})$/', $digits, $matches) === 1) {
                $whatsApp = 'https://wa.me/216'.$matches[1];
            }
        }
        $socialLinks = $this->input('social_links');
        if (is_array($socialLinks)) {
            $socialLinks = array_map(fn (mixed $url): mixed => is_string($url) ? rtrim(trim($url), '/') : $url, $socialLinks);
        }
        $this->merge(['whatsapp_url' => $whatsApp, 'social_links' => $socialLinks]);
    }

    public function authorize(): bool
    {
        return $this->user()?->can('store.manage') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'phone' => ['nullable', 'string', 'max:40'], 'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'], 'whatsapp_url' => ['nullable', 'string', 'max:500', function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_string($value) && $value !== '' && ! SafeUrl::isApprovedHttps($value, ['wa.me', 'api.whatsapp.com'])) {
                    $fail('Le lien WhatsApp n’est pas approuvé.');
                }
            }],
            'social_links' => ['array:instagram,facebook', 'max:4'], 'social_links.*' => ['nullable', 'string', 'max:500', function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_string($value) && $value !== '' && ! SafeUrl::isApprovedHttps($value, config('store.social_hosts'))) {
                    $fail('Ce lien social n’est pas approuvé.');
                }
            }],
            'announcement_text' => ['nullable', 'string', 'max:240'], 'footer_statement' => ['nullable', 'string', 'max:500'],
            'hero_autoplay_enabled' => ['required', 'boolean'],
        ];
    }
}
