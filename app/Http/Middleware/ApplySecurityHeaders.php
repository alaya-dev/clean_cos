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

        $policy = $this->policyWithLocalViteSources((string) config('security.content_security_policy'));
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

    private function policyWithLocalViteSources(string $policy): string
    {
        if ($policy === '' || ! app()->environment('local')) {
            return $policy;
        }

        $policy = preg_replace(
            '/script-src\s+([^;]+)/',
            "script-src $1 http://localhost:5173 http://127.0.0.1:5173 'unsafe-eval'",
            $policy,
            1
        ) ?? $policy;
        $policy = preg_replace(
            '/style-src\s+([^;]+)/',
            'style-src $1 http://localhost:5173 http://127.0.0.1:5173',
            $policy,
            1
        ) ?? $policy;
        $policy = preg_replace(
            '/connect-src\s+([^;]+)/',
            'connect-src $1 http://localhost:5173 http://127.0.0.1:5173 ws://localhost:5173 ws://127.0.0.1:5173',
            $policy,
            1
        ) ?? $policy;

        return $policy;
    }
}
