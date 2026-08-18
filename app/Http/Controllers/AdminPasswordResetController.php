<?php

namespace App\Http\Controllers;

use App\Domain\Audit\Actions\RecordAuditEventAction;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;

class AdminPasswordResetController extends Controller
{
    public function create(): View
    {
        return view('admin.password.email');
    }

    public function send(Request $request): RedirectResponse
    {
        $email = mb_strtolower(trim((string) $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ])['email']));

        $user = User::query()
            ->where('email', $email)
            ->where('is_active', true)
            ->whereIn('role', ['admin', 'super_admin'])
            ->first();

        if ($user !== null) {
            Password::broker()->sendResetLink(['email' => $email]);
        }

        return back()->with('status', 'Si cette adresse correspond à un accès administrateur actif, un lien de réinitialisation vient d’être envoyé. Il est valable 60 minutes.');
    }

    public function edit(Request $request, string $token): View
    {
        return view('admin.password.reset', [
            'token' => $token,
            'email' => $request->query('email', ''),
        ]);
    }

    public function update(Request $request, RecordAuditEventAction $audit): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ], [
            'password.min' => 'Le nouveau mot de passe doit contenir au moins 8 caractères.',
            'password.confirmed' => 'La confirmation du nouveau mot de passe ne correspond pas.',
        ]);

        $email = mb_strtolower(trim($data['email']));
        $user = User::query()
            ->where('email', $email)
            ->where('is_active', true)
            ->whereIn('role', ['admin', 'super_admin'])
            ->first();

        if ($user === null) {
            return back()->withInput($request->only('email'))->withErrors(['email' => 'Ce lien de réinitialisation est invalide ou a expiré.']);
        }

        $status = Password::broker()->reset(
            ['email' => $email, 'password' => $data['password'], 'token' => $data['token']],
            function (User $resetUser, string $password) use ($audit): void {
                $resetUser->force_password_change = false;
                $resetUser->password = $password;
                $resetUser->auth_version++;
                $resetUser->setRememberToken(str()->random(60));
                $resetUser->save();
                $audit->handle('user.password_reset', $resetUser);
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            return back()->withInput($request->only('email'))->withErrors(['email' => 'Ce lien de réinitialisation est invalide ou a expiré.']);
        }

        return redirect()->route('login')->with('status', 'Votre mot de passe a été réinitialisé. Vous pouvez vous connecter.');
    }
}
