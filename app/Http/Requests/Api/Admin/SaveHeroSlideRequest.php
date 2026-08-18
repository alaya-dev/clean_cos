<?php

namespace App\Http\Requests\Api\Admin;

use App\Support\Content\SafeUrl;
use Illuminate\Foundation\Http\FormRequest;

class SaveHeroSlideRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('store.manage') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $required = $this->route('heroSlide') === null;

        return [
            'admin_label' => [$required ? 'required' : 'sometimes', 'string', 'between:2,200'],
            'eyebrow' => ['nullable', 'string', 'max:160'], 'heading' => [$required ? 'required' : 'sometimes', 'string', 'between:2,240'],
            'supporting_text' => ['nullable', 'string', 'max:1000'], 'cta_label' => ['nullable', 'string', 'max:120'],
            'cta_url' => ['nullable', 'string', 'max:500', function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_string($value) && ! SafeUrl::isAllowed($value)) {
                    $fail('Le lien doit être interne ou utiliser HTTPS.');
                }
            }],
            'desktop_image' => [$required ? 'required' : 'nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:10240'],
            'mobile_image' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:10240'],
            'is_active' => [$required ? 'required' : 'sometimes', 'boolean'], 'sort_order' => [$required ? 'required' : 'sometimes', 'integer', 'between:0,1000'],
        ];
    }
}
