<?php

namespace App\Http\Middleware;

use App\Http\Responses\ApiErrorCode;
use App\Http\Responses\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnforceAdminSessionSecurity
{
    /**
     * Enforce absolute expiry and auth-version revocation for browser-backed
     * admin sessions. Token-backed test/API authentication remains governed by
     * its own guard and is deliberately not treated as a browser session.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->isAdminRequest($request) || ! $request->hasSession() || ! $request->session()->has('admin_authenticated_at')) {
            return $next($request);
        }

        $user = Auth::user();
        $issuedAt = (int) $request->session()->get('admin_authenticated_at', 0);
        $storedVersion = (int) $request->session()->get('admin_auth_version', -1);
        $expired = $issuedAt <= 0 || now()->getTimestamp() - $issuedAt > ((int) config('security.admin_absolute_session_minutes') * 60);
        $revoked = $user === null || ! $user->is_active || $storedVersion !== $user->auth_version;

        if (! $expired && ! $revoked) {
            return $next($request);
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->is('api/*')) {
            return ApiResponse::error(ApiErrorCode::UNAUTHENTICATED, 'Session expirée. Veuillez vous reconnecter.', 401);
        }

        return redirect()->route('login');
    }

    private function isAdminRequest(Request $request): bool
    {
        return $request->is('admin', 'admin/*', 'api/v1/admin/*');
    }
}
