<?php

namespace App\Http\Requests\Api\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveHomepageSectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('store.manage') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $required = $this->isMethod('post');

        return [
            // "curated" is an established, manually ordered homepage section type.
            // Existing seeded and production sections must remain editable.
            'type' => [$required ? 'required' : 'sometimes', Rule::in(['new_products', 'best_sellers', 'custom', 'curated'])],
            'eyebrow' => ['nullable', 'string', 'max:160'], 'title' => [$required ? 'required' : 'sometimes', 'string', 'between:2,200'],
            'description' => ['nullable', 'string', 'max:1000'], 'is_active' => [$required ? 'required' : 'sometimes', 'boolean'],
            'filters_enabled' => [$required ? 'required' : 'sometimes', 'boolean'], 'sort_order' => [$required ? 'required' : 'sometimes', 'integer', 'between:0,1000'],
            'product_public_ids' => ['array', 'max:24'], 'product_public_ids.*' => ['required', 'ulid', 'distinct', 'exists:products,public_id'],
        ];
    }
}
