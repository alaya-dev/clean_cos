<?php

namespace App\Http\Requests\Api\Admin;

use App\Domain\Promotions\Models\PromoCode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SavePromoCodeRequest extends FormRequest
{
    public function messages(): array
    {
        return ['code.unique' => 'Ce code promo existe déjà.'];
    }

    public function attributes(): array
    {
        return ['code' => 'code promo'];
    }

    public function authorize(): bool
    {
        return $this->user()?->can('store.manage') === true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('code')) {
            $this->merge(['code' => PromoCode::normalize((string) $this->input('code'))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $promoCode = $this->route('promoCode');
        $required = $this->isMethod('post');

        return [
            'code' => [$required ? 'required' : 'sometimes', 'string', 'max:80', 'regex:/^[A-Z0-9_-]+$/', Rule::unique('promo_codes', 'code')->ignore($promoCode)],
            'discount_percentage' => [$required ? 'required' : 'sometimes', 'integer', 'between:1,100'],
            'usage_limit' => [$required ? 'required' : 'sometimes', 'integer', 'min:1', function (string $attribute, mixed $value, \Closure $fail) use ($promoCode): void {
                if ($promoCode instanceof PromoCode && (int) $value < $promoCode->usage_count) {
                    $fail('La limite ne peut pas être inférieure au nombre d’utilisations.');
                }
            }],
            'minimum_subtotal_millimes' => ['nullable', 'integer', 'min:0'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'is_active' => [$required ? 'required' : 'sometimes', 'boolean'],
        ];
    }
}
