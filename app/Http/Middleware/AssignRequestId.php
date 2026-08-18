<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AssignRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $request->headers->get('X-Request-Id');
        $requestId = is_string($requestId) && preg_match('/^[A-Za-z0-9_-]{16,64}$/', $requestId)
            ? $requestId
            : (string) Str::uuid();

        $request->attributes->set('request_id', $requestId);
        $response = $next($request);
        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }
}
