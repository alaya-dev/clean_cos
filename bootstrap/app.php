<?php

use App\Domain\Promotions\Exceptions\PromoCodeUnavailable;
use App\Http\Middleware\ApplySecurityHeaders;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnforceAdminSessionSecurity;
use App\Http\Middleware\PersistMetaAttributionCookie;
use App\Http\Responses\ApiErrorCode;
use App\Http\Responses\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Cookie\Middleware\EncryptCookies as BaseEncryptCookies;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Sentry\Laravel\Integration;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR |
                Request::HEADER_X_FORWARDED_PROTO,
        );
        // The framework may retain the base middleware in cached route stacks;
        // register attribution cookies as globally unencrypted as well as in
        // the project subclass.
        BaseEncryptCookies::except(['_fbp', '_fbc']);
        App\Http\Middleware\EncryptCookies::except(['_fbp', '_fbc']);
        $middleware->replace(
            EncryptCookies::class,
            App\Http\Middleware\EncryptCookies::class,
        );
        $middleware->append(AssignRequestId::class);
        $middleware->append(ApplySecurityHeaders::class);
        $middleware->appendToGroup('web', EnforceAdminSessionSecurity::class);
        $middleware->appendToGroup('web', PersistMetaAttributionCookie::class);
        $middleware->redirectGuestsTo(fn (Request $request): ?string => $request->is('api/*') ? null : route('login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        Integration::handles($exceptions);

        $exceptions->render(function (Throwable $exception, Request $request) {
            // Let the specific API renderers below handle known contract
            // exceptions.  This fallback must only serialize unexpected
            // failures; otherwise every validation/auth/not-found/throttle
            // response is incorrectly turned into a 500.
            if (
                $exception instanceof PromoCodeUnavailable
                || $exception instanceof ValidationException
                || $exception instanceof AuthenticationException
                || $exception instanceof AuthorizationException
                || $exception instanceof ModelNotFoundException
                || $exception instanceof ThrottleRequestsException
                || $exception instanceof HttpExceptionInterface
            ) {
                return null;
            }

            if ($request->is('api/*')) {
                if ($exception instanceof TokenMismatchException || ($exception instanceof HttpExceptionInterface && $exception->getStatusCode() === 419)) {
                    return ApiResponse::error(
                        'CSRF_TOKEN_MISMATCH',
                        'Votre session a expiré. Actualisez la page, puis réessayez.',
                        419,
                        meta: ['request_id' => $request->attributes->get('request_id')],
                    );
                }

                return ApiResponse::error(ApiErrorCode::INTERNAL_ERROR, 'Une erreur inattendue est survenue.', 500, meta: ['request_id' => $request->attributes->get('request_id')]);
            }
        });

        $exceptions->render(function (PromoCodeUnavailable $exception, Request $request) {
            if ($request->is('api/v1/public/*')) {
                return ApiResponse::error('PROMO_CODE_INVALID', $exception->getMessage(), 422, meta: ['request_id' => $request->attributes->get('request_id')]);
            }
        });

        $exceptions->render(function (ValidationException $exception, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error(ApiErrorCode::VALIDATION_ERROR, 'La demande est invalide.', 422, $exception->errors(), ['request_id' => $request->attributes->get('request_id')]);
            }
        });

        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error(ApiErrorCode::UNAUTHENTICATED, 'Authentification requise.', 401, meta: ['request_id' => $request->attributes->get('request_id')]);
            }
        });

        $exceptions->render(function (AuthorizationException $exception, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error(ApiErrorCode::FORBIDDEN, 'Accès refusé.', 403, meta: ['request_id' => $request->attributes->get('request_id')]);
            }
        });

        $exceptions->render(function (ModelNotFoundException $exception, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error(ApiErrorCode::NOT_FOUND, 'Ressource introuvable.', 404, meta: ['request_id' => $request->attributes->get('request_id')]);
            }
        });

        $exceptions->render(function (ThrottleRequestsException $exception, Request $request) {
            if ($request->is('api/*')) {
                $response = ApiResponse::error(ApiErrorCode::RATE_LIMITED, 'Trop de requêtes. Réessayez plus tard.', 429, meta: ['request_id' => $request->attributes->get('request_id')]);
                $response->headers->set('Retry-After', '60');

                return $response;
            }
        });

        $exceptions->render(function (HttpExceptionInterface $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return match ($exception->getStatusCode()) {
                403 => ApiResponse::error(ApiErrorCode::FORBIDDEN, 'Accès refusé.', 403, meta: ['request_id' => $request->attributes->get('request_id')]),
                404 => ApiResponse::error(ApiErrorCode::NOT_FOUND, 'Ressource introuvable.', 404, meta: ['request_id' => $request->attributes->get('request_id')]),
                429 => ApiResponse::error(ApiErrorCode::RATE_LIMITED, 'Trop de requêtes. Réessayez plus tard.', 429, meta: ['request_id' => $request->attributes->get('request_id')]),
                419 => ApiResponse::error('CSRF_TOKEN_MISMATCH', 'Votre session a expiré. Actualisez la page, puis réessayez.', 419, meta: ['request_id' => $request->attributes->get('request_id')]),
                default => null,
            };
        });

    })->create();
