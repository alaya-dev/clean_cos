<?php

namespace App\Domain\MetaTracking\Services;

use App\Domain\MetaTracking\Models\MarketingConsent;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Crypt;

class MarketingConsentService
{
    /** @return array{necessary: bool, marketing: bool, policy_version: int, decided: bool} */
    public function current(Request $request): array
    {
        $receipt = $this->receiptFromRequest($request);
        if (! $receipt || $receipt->policy_version !== $this->policyVersion()) {
            return $this->defaultState();
        }

        return [
            'necessary' => true,
            'marketing' => $receipt->marketing_consent,
            'policy_version' => $receipt->policy_version,
            'decided' => true,
        ];
    }

    public function hasCurrentMarketingConsent(Request $request): bool
    {
        return $this->current($request)['marketing'];
    }

    /** @return array{necessary: bool, marketing: bool, policy_version: int, decided: true} */
    public function record(bool $marketing): array
    {
        $receipt = MarketingConsent::query()->create([
            'policy_version' => $this->policyVersion(),
            'necessary_consent' => true,
            'marketing_consent' => $marketing,
            'decided_at' => now(),
        ]);

        Cookie::queue(cookie(
            name: $this->cookieName(),
            value: Crypt::encryptString(json_encode([
                'receipt' => $receipt->public_id,
                'policy_version' => $receipt->policy_version,
            ], JSON_THROW_ON_ERROR)),
            minutes: (int) config('meta.consent_lifetime_days') * 24 * 60,
            path: '/',
            domain: null,
            secure: (bool) config('session.secure'),
            httpOnly: true,
            raw: false,
            sameSite: 'lax',
        ));

        return [
            'necessary' => true,
            'marketing' => $receipt->marketing_consent,
            'policy_version' => $receipt->policy_version,
            'decided' => true,
        ];
    }

    public function forget(): void
    {
        Cookie::queue(Cookie::forget($this->cookieName()));
    }

    private function receiptFromRequest(Request $request): ?MarketingConsent
    {
        $cookie = $request->cookie($this->cookieName());
        if (! is_string($cookie) || $cookie === '') {
            return null;
        }

        try {
            $payload = json_decode(Crypt::decryptString($cookie), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            return null;
        }

        $publicId = is_array($payload) ? ($payload['receipt'] ?? null) : null;

        return is_string($publicId) && preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $publicId)
            ? MarketingConsent::query()->where('public_id', $publicId)->first()
            : null;
    }

    /** @return array{necessary: true, marketing: false, policy_version: int, decided: false} */
    private function defaultState(): array
    {
        return ['necessary' => true, 'marketing' => false, 'policy_version' => $this->policyVersion(), 'decided' => false];
    }

    private function policyVersion(): int
    {
        return (int) config('meta.consent_policy_version');
    }

    /** @return non-empty-string */
    private function cookieName(): string
    {
        $name = config('meta.consent_cookie');
        if (! is_string($name) || $name === '') {
            throw new \LogicException('The Meta consent cookie name must be configured.');
        }

        return $name;
    }
}
