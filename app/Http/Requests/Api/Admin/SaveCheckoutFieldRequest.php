<?php

namespace App\Http\Requests\Api\Admin;

use App\Domain\Commerce\Models\CheckoutField;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveCheckoutFieldRequest extends FormRequest
{
    public function messages(): array
    {
        return ['key.unique' => 'Cette clé de champ existe déjà.'];
    }

    public function attributes(): array
    {
        return ['key' => 'clé du champ', 'label' => 'libellé français'];
    }

    public function authorize(): bool
    {
        return $this->user()?->can('store.manage') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $field = $this->route('checkoutField');
        $required = $this->isMethod('post');

        return [
            'key' => [$required ? 'required' : 'sometimes', 'string', 'regex:/^[a-z][a-z0-9_]{1,99}$/', Rule::unique('checkout_fields', 'key')->ignore($field)],
            'label' => [$required ? 'required' : 'sometimes', 'string', 'between:2,160'],
            'type' => [$required ? 'required' : 'sometimes', Rule::in(['text', 'textarea', 'number', 'select', 'radio', 'checkbox'])],
            'options' => ['nullable', 'array', 'max:50'],
            'options.*' => ['string', 'between:1,120', 'distinct'],
            'is_required' => [$required ? 'required' : 'sometimes', 'boolean'],
            'is_active' => [$required ? 'required' : 'sometimes', 'boolean'],
            'sort_order' => [$required ? 'required' : 'sometimes', 'integer', 'between:0,1000'],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function ($validator): void {
            $checkoutField = $this->route('checkoutField');
            $existingType = $checkoutField instanceof CheckoutField ? $checkoutField->type : null;
            $type = $this->input('type', $existingType);
            $options = $this->has('options') ? $this->input('options') : ($checkoutField instanceof CheckoutField ? $checkoutField->options : null);
            if (in_array($type, ['select', 'radio'], true) && (! is_array($options) || $options === [])) {
                $validator->errors()->add('options', 'Ajoutez au moins une option.');
            }
            if (! in_array($type, ['select', 'radio'], true) && $options !== null && $options !== []) {
                $validator->errors()->add('options', 'Ce type de champ n’accepte pas d’options.');
            }
        }];
    }
}
