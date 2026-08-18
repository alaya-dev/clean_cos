<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AdminAuthController extends Controller
{
    public function create(): View|RedirectResponse
    {
        return Auth::check() ? redirect()->route('admin.app') : view('admin.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        if (! Auth::attempt($credentials)) {
            return back()->withErrors(['email' => 'Identifiants invalides.'])->onlyInput('email');
        }
        $user = $request->user();
        if ($user === null || ! $user->is_active || ! in_array($user->role, ['admin', 'super_admin'], true)) {
            Auth::logout();

            return back()->withErrors(['email' => 'Identifiants invalides.'])->onlyInput('email');
        }
        $request->session()->regenerate();
        $request->session()->put([
            'admin_authenticated_at' => now()->timestamp,
            'admin_auth_version' => $user->auth_version,
        ]);

        return redirect()->intended(route('admin.app'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
