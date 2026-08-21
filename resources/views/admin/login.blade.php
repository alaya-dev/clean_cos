<!doctype html>
<html lang="fr">
<head>
<meta name="robots" content="noindex, nofollow, noarchive">
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><link rel="icon" type="image/jpeg" href="{{ asset('images/brand/cleancos-logo.jpg') }}"><title>Connexion · Clean'Cos</title>@vite(['resources/css/app.css'])</head>
<body><main class="admin-login"><section><p class="admin-eyebrow">Clean'Cos</p><h1>Administration</h1><p>Connectez-vous pour gérer le catalogue et les commandes.</p>@if(session('status'))<p class="admin-notice" role="status">{{ session('status') }}</p>@endif<form method="post" action="{{ route('admin.login') }}">@csrf<label>Adresse e-mail<input name="email" type="email" value="{{ old('email') }}" autocomplete="username" required autofocus></label><label>Mot de passe<input name="password" type="password" autocomplete="current-password" required></label>@error('email')<p class="admin-alert">{{ $message }}</p>@enderror<button class="admin-action">Se connecter</button></form><a class="text-link admin-login-help" href="{{ route('admin.password.request') }}">Mot de passe oublié ?</a></section></main></body></html>
