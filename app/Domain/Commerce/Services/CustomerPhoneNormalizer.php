<?php

namespace App\Domain\Commerce\Services;

class CustomerPhoneNormalizer
{
    public function normalize(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', trim($phone)) ?? '';
        if (str_starts_with($digits, '00216')) {
            $digits = substr($digits, 5);
        } elseif (str_starts_with($digits, '216') && strlen($digits) > 8) {
            $digits = substr($digits, 3);
        } elseif (str_starts_with($digits, '0') && strlen($digits) > 8) {
            $digits = ltrim($digits, '0');
        }

        return $digits;
    }
}
