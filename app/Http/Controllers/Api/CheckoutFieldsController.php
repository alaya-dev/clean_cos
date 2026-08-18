<?php

namespace App\Http\Controllers\Api;

use App\Domain\Checkout\Actions\ResolveCheckoutSubmissionAction;
use App\Domain\Checkout\Support\TunisianGovernorates;
use App\Domain\Commerce\Models\CheckoutField;
use App\Domain\Settings\Services\StoreSettings;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CheckoutFieldsController extends Controller
{
    public function __invoke(Request $request, ResolveCheckoutSubmissionAction $resolver, StoreSettings $settings): JsonResponse
    {
        $fields = CheckoutField::query()->where('is_active', true)->orderBy('sort_order')->get();
        $data = $fields->map(function (CheckoutField $field): array {
            $data = $field->only(['key', 'label', 'type', 'is_required', 'options', 'sort_order']);

            if ($field->key === 'governorate') {
                $data['options'] = TunisianGovernorates::ALL;
            }

            return $data;
        })->values();

        return ApiResponse::success($data, ['schema_version' => $resolver->schemaVersion($data->all()), 'promo_code_field_visible' => (bool) $settings->get('checkout.promo_field_visible'), 'request_id' => $request->attributes->get('request_id')]);
    }
}
