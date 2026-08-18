<?php

namespace App\Http\Controllers\Api;

use App\Domain\MetaTracking\Services\MarketingConsentService;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MarketingConsentController extends Controller
{
    public function show(Request $request, MarketingConsentService $consent): JsonResponse
    {
        return ApiResponse::success($consent->current($request));
    }

    public function store(Request $request, MarketingConsentService $consent): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:accept_all,refuse_optional,save_preferences,withdraw'],
            'marketing' => ['required_if:decision,save_preferences', 'boolean'],
        ]);

        if ($data['decision'] === 'withdraw') {
            $consent->forget();

            return ApiResponse::success(['necessary' => true, 'marketing' => false, 'policy_version' => (int) config('meta.consent_policy_version'), 'decided' => false]);
        }

        $marketing = match ($data['decision']) {
            'accept_all' => true,
            'refuse_optional' => false,
            default => (bool) $data['marketing'],
        };

        return ApiResponse::success($consent->record($marketing));
    }
}
