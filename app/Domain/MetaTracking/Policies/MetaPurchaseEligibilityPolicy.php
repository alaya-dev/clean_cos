<?php

namespace App\Domain\MetaTracking\Policies;

use App\Domain\Commerce\Models\Order;
use App\Domain\MetaTracking\Services\MarketingConsentService;
use App\Domain\MetaTracking\Services\MetaConfigurationService;
use Illuminate\Http\Request;

class MetaPurchaseEligibilityPolicy
{
    public function __construct(
        private readonly MarketingConsentService $consent,
        private readonly MetaConfigurationService $configurations,
    ) {}

    public function eligible(Order $order, Request $request): bool
    {
        return $this->ineligibilityReason($order, $request) === null;
    }

    /**
     * @return 'order_status_not_eligible'|'consent_denied'|'tracking_not_active'|null
     */
    public function ineligibilityReason(Order $order, Request $request): ?string
    {
        if ($order->status !== 'nouvelle') {
            return 'order_status_not_eligible';
        }
        if (! $this->consent->hasCurrentMarketingConsent($request)) {
            return 'consent_denied';
        }

        return $this->configurations->active() === null ? 'tracking_not_active' : null;
    }
}
