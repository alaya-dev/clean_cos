<?php

namespace App\Support\Content;

class SafeUrl
{
    /** @param array<int, string> $approvedHosts */
    public static function isAllowed(?string $url, array $approvedHosts = []): bool
    {
        if ($url === null || $url === '') {
            return true;
        }
        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            return true;
        }
        $parts = parse_url($url);
        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || ! isset($parts['host'])) {
            return false;
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }
        if (isset($parts['port']) && (int) $parts['port'] !== 443) {
            return false;
        }

        return $approvedHosts === [] || in_array(mb_strtolower($parts['host']), $approvedHosts, true);
    }

    /** @param array<int, string> $approvedHosts */
    public static function isApprovedHttps(?string $url, array $approvedHosts): bool
    {
        return is_string($url)
            && str_starts_with($url, 'https://')
            && self::isAllowed($url, $approvedHosts);
    }
}
