<!doctype html>
<html lang="fr">
<head>
<meta name="robots" content="noindex, nofollow, noarchive">
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Réinitialiser le mot de passe · ToutDispo</title>@vite(['resources/css/app.css'])</head>
<body><main class="admin-login"><section><p class="admin-eyebrow">ToutDispo</p><h1>Mot de passe oublié ?</h1><p>Saisissez votre adresse e-mail administrateur. Si elle est reconnue, vous recevrez un lien valable 60 minutes.</p>@if(session('status'))<p class="admin-notice" role="status">{{ session('status') }}</p>@endif<form method="post" action="{{ route('admin.password.email') }}">@csrf<label>Adresse e-mail<input name="email" type="email" value="{{ old('email') }}" autocomplete="email" required autofocus></label>@error('email')<p class="admin-alert">{{ $message }}</p>@enderror<button class="admin-action">Envoyer le lien</button></form><a class="text-link" href="{{ route('login') }}">Retour à la connexion</a></section></main></body></html>
