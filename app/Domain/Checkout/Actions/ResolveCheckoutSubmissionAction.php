<?php

namespace App\Domain\Checkout\Actions;

use App\Domain\Checkout\Support\PhoneNumberRule;
use App\Domain\Checkout\Support\TunisianGovernorates;
use App\Domain\Commerce\Exceptions\CheckoutConflictException;
use App\Domain\Commerce\Models\CheckoutField;
use App\Domain\Settings\Services\StoreSettings;
use Illuminate\Validation\ValidationException;

class ResolveCheckoutSubmissionAction
{
    private const FIXED_FIELDS = ['full_name', 'phone', 'city', 'governorate', 'address'];

    public function __construct(private readonly StoreSettings $settings) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array{customer: array<string, mixed>, items: array<int, array<string, mixed>>, checkout_schema_version: string, checkout_fields: array<int, array<string, mixed>>, checkout_values: array<int, array<string, mixed>>}
     */
    public function handle(array $data): array
    {
        $fields = CheckoutField::query()->where('is_active', true)->orderBy('sort_order')->get();
        $checkoutFields = $fields->map(fn (CheckoutField $field): array => $field->only(['key', 'label', 'type', 'is_required', 'options', 'sort_order']))->values()->all();
        $schemaVersion = $this->schemaVersion($checkoutFields);
        if (! hash_equals($schemaVersion, (string) $data['checkout_schema_version'])) {
            throw new CheckoutConflictException('CHECKOUT_SCHEMA_STALE', 'Le formulaire de commande a changé. Rechargez la page avant de continuer.');
        }

        $customer = $data['customer'];
        $allowedKeys = array_values(array_unique(array_merge(self::FIXED_FIELDS, array_column($checkoutFields, 'key'))));
        $unknownKeys = array_values(array_diff(array_keys($customer), $allowedKeys));
        if ($unknownKeys !== []) {
            throw ValidationException::withMessages(['customer' => 'Un champ de livraison n’est plus autorisé.']);
        }

        $resolvedCustomer = [];
        foreach (self::FIXED_FIELDS as $field) {
            if (! array_key_exists($field, $customer) || $this->blank($customer[$field])) {
                throw ValidationException::withMessages(['customer.'.$field => 'Ce champ est requis.']);
            }

            $resolvedCustomer[$field] = $this->normalizeFixedField($field, $customer[$field]);
        }

        $checkoutValues = [];
        foreach ($fields as $field) {
            $value = $customer[$field->key] ?? null;
            if ($this->blank($value)) {
                if ($field->is_required) {
                    throw ValidationException::withMessages(['customer.'.$field->key => 'Ce champ est requis.']);
                }

                continue;
            }

            $normalized = $this->normalizeFieldValue($field, $value);
            $resolvedCustomer[$field->key] = $normalized;
            $checkoutValues[] = [
                'checkout_field_id' => $field->id,
                'field_key_snapshot' => $field->key,
                'label_snapshot' => $field->label,
                'type_snapshot' => $field->type,
                'is_required_snapshot' => $field->is_required,
                'value' => $normalized,
            ];
        }

        return [
            'customer' => $resolvedCustomer,
            'items' => $data['items'],
            'checkout_schema_version' => $schemaVersion,
            'checkout_fields' => $checkoutFields,
            'checkout_values' => $checkoutValues,
        ];
    }

    /** @param array<int, array<string, mixed>> $fields */
    public function schemaVersion(array $fields): string
    {
        return hash('sha256', (string) $this->settings->get('checkout.schema_version'));
    }

    private function blank(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }

    private function normalizeFixedField(string $field, mixed $value): string
    {
        $value = is_string($value) ? trim($value) : (string) $value;

        if ($field === 'governorate' && ! in_array($value, TunisianGovernorates::ALL, true)) {
            throw ValidationException::withMessages(['customer.governorate' => 'Le gouvernorat sélectionné est invalide.']);
        }

        if ($field === 'phone' && ! PhoneNumberRule::isValid($value)) {
            throw ValidationException::withMessages(['customer.phone' => PhoneNumberRule::MESSAGE]);
        }

        return $field === 'phone' ? preg_replace('/[^0-9+]/', '', $value) ?? $value : $value;
    }

    private function normalizeFieldValue(CheckoutField $field, mixed $value): mixed
    {
        return match ($field->type) {
            'textarea', 'text' => is_string($value) ? trim($value) : (string) $value,
            'number' => is_numeric($value) ? (int) $value : throw ValidationException::withMessages(['customer.'.$field->key => 'Cette valeur est invalide.']),
            'checkbox' => filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? throw ValidationException::withMessages(['customer.'.$field->key => 'Cette valeur est invalide.']),
            'select', 'radio' => $this->normalizeChoiceField($field, $value),
            default => throw ValidationException::withMessages(['customer.'.$field->key => 'Ce type de champ n’est pas autorisé.']),
        };
    }

    private function normalizeChoiceField(CheckoutField $field, mixed $value): string
    {
        $candidate = is_string($value) ? trim($value) : (string) $value;
        $options = $this->optionValues($field);
        if ($options !== [] && ! in_array($candidate, $options, true)) {
            throw ValidationException::withMessages(['customer.'.$field->key => 'Cette valeur est invalide.']);
        }

        return $candidate;
    }

    /** @return array<int, string> */
    private function optionValues(CheckoutField $field): array
    {
        $raw = json_decode(json_encode($field->options ?? [], JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        $values = [];
        foreach ((array) $raw as $option) {
            $values[] = is_array($option) ? (string) ($option['value'] ?? $option['label'] ?? '') : (string) $option;
        }

        return $values;
    }
}
