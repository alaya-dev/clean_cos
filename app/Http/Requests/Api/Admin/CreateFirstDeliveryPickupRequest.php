<?php

namespace App\Http\Requests\Api\Admin;

use Illuminate\Foundation\Http\FormRequest;

class CreateFirstDeliveryPickupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('catalog.manage') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'shipment_public_ids' => ['required', 'array', 'between:1,100'],
            'shipment_public_ids.*' => ['required', 'string', 'ulid', 'distinct'],
            'confirm_creation' => ['required', 'accepted'],
        ];
    }
}
