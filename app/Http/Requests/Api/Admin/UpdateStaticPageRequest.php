<?php

namespace App\Http\Requests\Api\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStaticPageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('store.manage') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $page = $this->route('staticPage');

        return [
            'title' => ['sometimes', 'required', 'string', 'between:2,200'],
            'slug' => ['sometimes', 'required', 'string', 'max:190', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('static_pages', 'slug')->ignore($page)],
            'content' => ['sometimes', 'required', 'string', 'max:100000'],
            'is_active' => ['sometimes', 'boolean'],
            'seo_title' => ['nullable', 'string', 'max:255'],
            'seo_description' => ['nullable', 'string', 'max:320'],
        ];
    }
}
