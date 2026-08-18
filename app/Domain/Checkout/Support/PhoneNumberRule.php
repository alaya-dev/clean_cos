<?php

namespace App\Domain\Checkout\Support;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class PhoneNumberRule implements ValidationRule
{
    public const MESSAGE = 'Le numéro de téléphone doit contenir au moins 8 chiffres.';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! self::isValid($value)) {
            $fail(self::MESSAGE);
        }
    }

    public static function isValid(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }

        $value = trim($value);
        if ($value === '' || preg_match('/^[+]?[0-9()\s-]+$/', $value) !== 1) {
            return false;
        }

        $digits = preg_replace('/\D/', '', $value) ?? '';

        return strlen($digits) >= 8 && strlen($digits) <= 15;
    }
}
