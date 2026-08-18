<?php

namespace App\Http\Controllers\Api;

use App\Domain\MetaTracking\Policies\MetaEventEligibilityPolicy;
use App\Domain\MetaTracking\Services\MetaConfigurationService;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MetaPixelConfigurationController extends Controller
{
    public function __invoke(Request $request, MetaEventEligibilityPolicy $eligibility, MetaConfigurationService $configurations): JsonResponse
    {
        if (! $eligibility->eligible($request)) {
            return ApiResponse::success(['pixel_id' => null]);
        }

        return ApiResponse::success(['pixel_id' => $configurations->active()?->pixel_id]);
    }
}
