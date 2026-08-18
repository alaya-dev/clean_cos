<?php

namespace App\Http\Requests\Api\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateShippingSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('store.manage') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'fixed_fee_millimes' => ['required', 'integer', 'min:0'],
            'free_threshold_enabled' => ['required', 'boolean'],
            'free_threshold_millimes' => ['nullable', 'required_if:free_threshold_enabled,true', 'integer', 'min:0'],
        ];
    }
}
