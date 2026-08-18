<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Audit\Actions\RecordAuditEventAction;
use App\Domain\Checkout\Services\ShippingCalculator;
use App\Domain\Settings\Models\Setting;
use App\Domain\Settings\Services\StoreSettings;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Admin\UpdateShippingSettingsRequest;
use App\Http\Requests\Api\Admin\UpdateStoreSettingsRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function shipping(StoreSettings $settings): JsonResponse
    {
        return response()->json(['data' => [
            'fixed_fee_millimes' => $settings->get('shipping.fixed_fee_millimes'),
            'free_threshold_enabled' => $settings->get('shipping.free_threshold_enabled'),
            'free_threshold_millimes' => $settings->get('shipping.free_threshold_millimes'),
        ]]);
    }

    public function updateShipping(UpdateShippingSettingsRequest $request, StoreSettings $settings, ShippingCalculator $shipping, RecordAuditEventAction $audit): JsonResponse
    {
        $actor = $this->actor($request);
        $payload = $request->validated();
        $before = [
            'fixed_fee_millimes' => $settings->get('shipping.fixed_fee_millimes'),
            'free_threshold_enabled' => $settings->get('shipping.free_threshold_enabled'),
            'free_threshold_millimes' => $settings->get('shipping.free_threshold_millimes'),
        ];
        $settings->update([
            'shipping.fixed_fee_millimes' => $payload['fixed_fee_millimes'],
            'shipping.free_threshold_enabled' => $payload['free_threshold_enabled'],
            'shipping.free_threshold_millimes' => $payload['free_threshold_millimes'],
        ], $actor->id);
        $record = Setting::query()->where('key', 'shipping.fixed_fee_millimes')->firstOrFail();
        $audit->handle('settings.shipping_updated', $record, $actor, $before, $payload);

        return response()->json(['data' => $payload, 'preview' => $shipping->calculate($payload['free_threshold_millimes'] ?? 0)]);
    }

    public function store(StoreSettings $settings): JsonResponse
    {
        return response()->json(['data' => $this->storePayload($settings)]);
    }

    public function updateStore(UpdateStoreSettingsRequest $request, StoreSettings $settings, RecordAuditEventAction $audit): JsonResponse
    {
        $actor = $this->actor($request);
        $before = $this->storePayload($settings);
        $values = [];
        foreach ($request->validated() as $key => $value) {
            $values['store.'.$key] = $value;
        }
        $settings->update($values, $actor->id);
        $record = Setting::query()->where('key', (string) array_key_first($values))->firstOrFail();
        $audit->handle('content.store_settings_updated', $record, $actor, $before, $request->validated());

        return response()->json(['data' => $this->storePayload($settings)]);
    }

    public function updateCheckout(Request $request, StoreSettings $settings, RecordAuditEventAction $audit): JsonResponse
    {
        $actor = $this->actor($request);
        $payload = $request->validate(['promo_code_field_visible' => ['required', 'boolean']]);
        $before = (bool) $settings->get('checkout.promo_field_visible');
        $settings->update(['checkout.promo_field_visible' => $payload['promo_code_field_visible']], $actor->id);
        $record = Setting::query()->where('key', 'checkout.promo_field_visible')->firstOrFail();
        $audit->handle('settings.checkout_updated', $record, $actor, ['promo_code_field_visible' => $before], $payload);

        return response()->json(['data' => $payload]);
    }

    /** @return array<string, mixed> */
    private function storePayload(StoreSettings $settings): array
    {
        $payload = [];
        foreach (['phone', 'email', 'address', 'whatsapp_url', 'social_links', 'announcement_text', 'footer_statement', 'hero_autoplay_enabled'] as $key) {
            $payload[$key] = $settings->get('store.'.$key);
        }

        return $payload;
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            abort(401);
        }

        return $actor;
    }
}
