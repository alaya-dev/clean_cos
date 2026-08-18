<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class SubmitComplaintRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'customer_name' => ['required', 'string', 'between:2,180'], 'customer_phone' => ['required', 'string', 'max:40'],
            'order_reference' => ['nullable', 'ulid'], 'subject' => ['required', 'string', 'between:3,200'],
            'description' => ['required', 'string', 'between:10,5000'], 'attachment' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:5120'],
            'consent' => ['accepted'], 'website' => ['nullable', 'string', 'max:200'],
        ];
    }
}
