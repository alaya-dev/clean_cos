<?php

namespace App\Support\Media;

/**
 * Builds canonical same-origin URLs for safe public media derivatives.
 *
 * The application can be served from localhost, 127.0.0.1, or a deployment
 * host. A relative URL deliberately follows the current origin instead of
 * leaking an APP_URL value captured from another environment.
 */
final class PublicMediaUrl
{
    public function forPath(?string $path): ?string
    {
        if (! is_string($path) || $path === '') {
            return null;
        }

        $normalizedPath = trim($path, '/');
        if ($normalizedPath === '') {
            return null;
        }
        $segments = explode('/', $normalizedPath);
        if (in_array('..', $segments, true) || in_array('.', $segments, true)) {
            return null;
        }

        return '/storage/'.implode('/', array_map(rawurlencode(...), $segments));
    }
}
