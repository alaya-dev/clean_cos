<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title }} | ToutDispo</title>
    <style>body{margin:0;background:#fffaf7;color:#31282b;font:16px/1.55 system-ui,sans-serif}.error-page{display:grid;min-height:100dvh;place-items:center;padding:24px}.error-card{width:min(100%,42rem);padding:clamp(1.5rem,5vw,3rem);border:1px solid #e2d6d6;background:#fff;text-align:center}.brand{font-weight:800;letter-spacing:.08em}.code{margin:1rem 0;color:#9f5269;font:700 .75rem/1 ui-monospace,monospace;letter-spacing:.12em}.error-card h1{margin:0;font-size:clamp(2rem,7vw,3.6rem);line-height:1.05}.error-card p{max-width:42ch;margin:1rem auto 0;color:#675b5e}.actions{display:flex;flex-wrap:wrap;justify-content:center;gap:.75rem;margin-top:1.75rem}.button{display:inline-flex;align-items:center;justify-content:center;min-height:44px;padding:.7rem 1rem;border:1px solid #31282b;color:#31282b;font-weight:700;text-decoration:none}.button-primary{background:#31282b;color:#fff}@media(max-width:360px){.actions{display:grid}.button{width:100%}}</style>
</head>
<body><main class="error-page"><section class="error-card"><div class="brand">ToutDispo</div><div class="code">ERREUR {{ $status }}</div><h1>{{ $title }}</h1><p>{{ $message }}</p><div class="actions">@foreach($actions as $action)<a class="button {{ $loop->first ? 'button-primary' : '' }}" href="{{ $action['href'] }}">{{ $action['label'] }}</a>@endforeach</div></section></main></body>
</html>
