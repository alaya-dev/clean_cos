<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApplySecurityHeaders
{
    /**
     * Apply a conservative, deployment-neutral browser security baseline.
     * The policy is report-only by default so local Vite and a future approved
     * tracking host can be observed before production enforcement.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), geolocation=(), microphone=(), payment=(), usb=()');
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');

        $policy = (string) config('security.content_security_policy');
        if ($policy !== '' && ! $response->headers->has('Content-Security-Policy')) {
            $header = config('security.csp_mode') === 'enforce'
                ? 'Content-Security-Policy'
                : 'Content-Security-Policy-Report-Only';
            $response->headers->set($header, $policy);
        }

        if ($request->isSecure() && (bool) config('security.hsts_enabled')) {
            $response->headers->set('Strict-Transport-Security', 'max-age=63072000; includeSubDomains');
        }

        if ($this->isPrivateResponse($request)) {
            $response->headers->set('Cache-Control', 'no-store, private');
            $response->headers->set('Pragma', 'no-cache');
        }

        return $response;
    }

    private function isPrivateResponse(Request $request): bool
    {
        return $request->is('admin', 'admin/*', 'api/v1/admin/*', 'commande/confirmee/*');
    }
}
