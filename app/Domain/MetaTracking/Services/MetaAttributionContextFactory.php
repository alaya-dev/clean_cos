<?php

namespace App\Domain\MetaTracking\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Captures browser attribution at the HTTP boundary before a Meta event is queued.
 * Values returned here are encrypted with the Meta event and must never be logged.
 */
class MetaAttributionContextFactory
{
    /** @return array<string, string> */
    public function capture(Request $request, ?string $phone = null): array
    {
        $fbc = $this->validAttributionCookie($request->cookie('_fbc'));

        if ($fbc === null) {
            $fbc = $this->fromClickId($request->query('fbclid'), now()->getTimestampMs());
        }

        return array_filter([
            'client_ip_address' => $this->validIp($request->ip()),
            'client_user_agent' => $this->userAgent($request->userAgent()),
            'fbp' => $this->validAttributionCookie($request->cookie('_fbp')),
            'fbc' => $fbc,
            'ph' => $this->hashTunisianPhone($phone),
        ], static fn (?string $value): bool => $value !== null);
    }

    public function hashTunisianPhone(?string $phone): ?string
    {
        $normalized = $this->normalizeTunisianPhone($phone);

        return $normalized === null ? null : hash('sha256', $normalized);
    }

    public function normalizeTunisianPhone(?string $phone): ?string
    {
        if (! is_string($phone)) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (Str::startsWith($digits, '00')) {
            $digits = substr($digits, 2);
        }
        if (preg_match('/^\d{8}$/', $digits)) {
            $digits = '216'.$digits;
        }

        return preg_match('/^216\d{8}$/', $digits) === 1 ? $digits : null;
    }

    private function validIp(mixed $value): ?string
    {
        return is_string($value) && filter_var($value, FILTER_VALIDATE_IP) ? $value : null;
    }

    private function userAgent(?string $value): ?string
    {
        $value = mb_substr((string) $value, 0, 500);

        return $value !== '' ? $value : null;
    }

    private function validAttributionCookie(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^fb\.\d+\.\d+\.[A-Za-z0-9_-]{1,500}$/', $value) === 1 ? $value : null;
    }

    public function fromClickId(mixed $clickId, ?int $timestampMilliseconds = null): ?string
    {
        if (! is_string($clickId) || preg_match('/^[A-Za-z0-9_-]{1,500}$/', $clickId) !== 1) {
            return null;
        }

        // Meta's documented fbc format for a valid fbclid when _fbc is absent.
        return 'fb.1.'.($timestampMilliseconds ?? now()->getTimestampMs()).'.'.$clickId;
    }
}
