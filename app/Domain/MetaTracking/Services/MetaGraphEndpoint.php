<?php

namespace App\Domain\MetaTracking\Services;

use InvalidArgumentException;

class MetaGraphEndpoint
{
    public function events(string $pixelId): string
    {
        $version = (string) config('meta.graph_api_version', 'v25.0');
        if (preg_match('/^v\d+\.\d+$/', $version) !== 1 || preg_match('/^\d{5,30}$/', $pixelId) !== 1) {
            throw new InvalidArgumentException('Invalid Meta Graph endpoint configuration.');
        }

        return "https://graph.facebook.com/{$version}/{$pixelId}/events";
    }
}
