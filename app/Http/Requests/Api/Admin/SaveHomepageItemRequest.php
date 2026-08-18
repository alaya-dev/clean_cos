<?php

namespace App\Http\Requests\Api\Admin;

use App\Support\Content\SafeUrl;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveHomepageItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('store.manage') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $required = $this->route('contentItem') === null;

        return match ($this->route('contentType')) {
            'visual-tiles' => [
                'category_public_id' => [$required ? 'required' : 'sometimes', 'ulid', 'exists:categories,public_id'],
                'label' => [$required ? 'required' : 'sometimes', 'string', 'between:2,160'],
                'desktop_image' => [$required ? 'required' : 'nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:10240'],
                'mobile_image' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:10240'],
                ...$this->stateRules($required),
            ],
            'reassurance' => [
                'icon' => [$required ? 'required' : 'sometimes', Rule::in(config('store.approved_icons'))],
                'title' => [$required ? 'required' : 'sometimes', 'string', 'between:2,160'],
                'text' => [$required ? 'required' : 'sometimes', 'string', 'between:2,300'], ...$this->stateRules($required),
            ],
            'social' => [
                'image' => [$required ? 'required' : 'nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:10240'],
                'url' => [$required ? 'required' : 'sometimes', 'string', 'max:500', function (string $attribute, mixed $value, \Closure $fail): void {
                    if (is_string($value) && ! SafeUrl::isApprovedHttps($value, config('store.social_hosts'))) {
                        $fail('Ce réseau social n’est pas approuvé.');
                    }
                }],
                'alt_text' => [$required ? 'required' : 'sometimes', 'string', 'between:2,255'], ...$this->stateRules($required),
            ],
            'editorial' => [
                'eyebrow' => ['nullable', 'string', 'max:160'], 'heading' => [$required ? 'required' : 'sometimes', 'string', 'between:2,240'],
                'description' => ['nullable', 'string', 'max:1500'], 'cta_label' => ['nullable', 'string', 'max:120'],
                'cta_url' => ['nullable', 'string', 'max:500', function (string $attribute, mixed $value, \Closure $fail): void {
                    if (is_string($value) && ! SafeUrl::isAllowed($value)) {
                        $fail('Le lien doit être interne ou utiliser HTTPS.');
                    }
                }],
                'image' => [$required ? 'required' : 'nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:10240'], 'is_active' => [$required ? 'required' : 'sometimes', 'boolean'],
                'product_public_ids' => ['array', 'max:12'], 'product_public_ids.*' => ['ulid', 'distinct', 'exists:products,public_id'],
            ],
            'brand' => ['heading' => [$required ? 'required' : 'sometimes', 'string', 'between:2,240'], 'content' => [$required ? 'required' : 'sometimes', 'string', 'max:100000'], 'is_active' => [$required ? 'required' : 'sometimes', 'boolean']],
            default => [],
        };
    }

    /** @return array<string, mixed> */
    private function stateRules(bool $required): array
    {
        return ['is_active' => [$required ? 'required' : 'sometimes', 'boolean'], 'sort_order' => [$required ? 'required' : 'sometimes', 'integer', 'between:0,1000']];
    }
}
