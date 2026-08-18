<?php

namespace App\Http\Requests\Api\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UploadCategoryImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('catalog.manage') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['image' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:10240']];
    }
}
