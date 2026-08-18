<?php

namespace App\Http\Middleware;

use App\Domain\MetaTracking\Services\MarketingConsentService;
use App\Domain\MetaTracking\Services\MetaAttributionContextFactory;
use App\Domain\MetaTracking\Services\MetaConfigurationService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Persists a server-observed Meta click identifier only after marketing
 * consent and an active Meta configuration have been established.
 *
 * The browser Pixel can still own its normal cookie lifecycle. This small
 * fallback keeps server CAPI attribution intact when the Pixel is delayed or
 * blocked, without creating a marketing cookie for undecided visitors.
 */
class PersistMetaAttributionCookie
{
    private const PENDING_CLICK_ID = 'pc_meta_pending_fbclid';

    public function __construct(
        private readonly MarketingConsentService $consent,
        private readonly MetaConfigurationService $configurations,
        private readonly MetaAttributionContextFactory $attribution,
    ) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $isConsentEndpoint = $request->is('api/v1/public/marketing-consent');
        if ((! $request->isMethodSafe() && ! $isConsentEndpoint) || $request->is('admin', 'admin/*')) {
            return $response;
        }

        $hasConsent = $this->consent->hasCurrentMarketingConsent($request);
        $clickId = $this->validClickId($request->query('fbclid'));
        $pendingClickId = $request->hasSession() ? $this->validClickId($request->session()->get(self::PENDING_CLICK_ID)) : null;
        if (! $hasConsent && $isConsentEndpoint) {
            $decision = (string) $request->input('decision');
            $hasConsent = $decision === 'accept_all' || ($decision === 'save_preferences' && $request->boolean('marketing'));
        }

        if (! $hasConsent) {
            if ($clickId !== null && $request->isMethod('GET') && $request->hasSession()) {
                $request->session()->put(self::PENDING_CLICK_ID, $clickId);
            }

            if ($isConsentEndpoint && in_array((string) $request->input('decision'), ['refuse_optional', 'withdraw'], true) && $request->hasSession()) {
                $request->session()->forget(self::PENDING_CLICK_ID);
            }

            return $response;
        }

        if ($request->cookie('_fbc') !== null || $this->configurations->active() === null) {
            return $response;
        }

        $fbc = $this->attribution->capture($request)['fbc'] ?? null;
        $fbc ??= $this->attribution->fromClickId($pendingClickId);
        if (! is_string($fbc)) {
            return $response;
        }

        if ($request->hasSession()) {
            $request->session()->forget(self::PENDING_CLICK_ID);
        }

        $response->headers->setCookie(cookie(
            name: '_fbc',
            value: $fbc,
            minutes: 90 * 24 * 60,
            path: '/',
            domain: null,
            secure: $request->isSecure() || (bool) config('session.secure'),
            httpOnly: false,
            raw: false,
            sameSite: 'lax',
        ));

        return $response;
    }

    private function validClickId(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[A-Za-z0-9_-]{1,500}$/', $value) === 1 ? $value : null;
    }
}
