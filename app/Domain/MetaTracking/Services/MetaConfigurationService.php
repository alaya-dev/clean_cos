<?php

namespace App\Domain\MetaTracking\Services;

use App\Domain\MetaTracking\Models\MetaConfiguration;
use Illuminate\Support\Facades\Cache;

class MetaConfigurationService
{
    public function active(): ?MetaConfiguration
    {
        return MetaConfiguration::query()
            ->where('state', 'active')
            ->where('tracking_enabled', true)
            ->whereNotNull('pixel_id')
            ->whereNotNull('capi_access_token_encrypted')
            ->latest('activated_at')
            ->first();
    }

    public function facebookDomainVerification(): ?string
    {
        return Cache::remember('meta:active-domain-verification', now()->addMinutes(5), fn (): ?string => MetaConfiguration::query()
            ->where('state', 'active')
            ->latest('activated_at')
            ->value('facebook_domain_verification'));
    }

    public function forgetFacebookDomainVerification(): void
    {
        Cache::forget('meta:active-domain-verification');
    }
}
