<?php

namespace App\Domain\MetaTracking\Policies;

use App\Domain\MetaTracking\Services\MarketingConsentService;
use App\Domain\MetaTracking\Services\MetaConfigurationService;
use Illuminate\Http\Request;

class MetaEventEligibilityPolicy
{
    public function __construct(
        private readonly MarketingConsentService $consent,
        private readonly MetaConfigurationService $configurations,
    ) {}

    public function eligible(Request $request): bool
    {
        return $this->consent->hasCurrentMarketingConsent($request)
            && $this->configurations->active() !== null;
    }
}
