<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Audit\Actions\RecordAuditEventAction;
use App\Domain\Commerce\Models\CheckoutField;
use App\Domain\Settings\Services\StoreSettings;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Admin\SaveCheckoutFieldRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CheckoutFieldController extends Controller
{
    public function index(StoreSettings $settings): JsonResponse
    {
        return response()->json([
            'data' => CheckoutField::query()->orderBy('sort_order')->get(),
            'meta' => ['promo_code_field_visible' => (bool) $settings->get('checkout.promo_field_visible')],
        ]);
    }

    public function store(SaveCheckoutFieldRequest $request, StoreSettings $settings, RecordAuditEventAction $audit): JsonResponse
    {
        $actor = $this->actor($request);
        $checkoutField = DB::transaction(function () use ($request, $settings, $audit, $actor): CheckoutField {
            $checkoutField = CheckoutField::query()->create($request->validated() + ['is_system' => false]);
            $settings->incrementSchemaVersion($actor->id);
            $audit->handle('checkout.field_created', $checkoutField, $actor, after: $checkoutField->only(['key', 'label', 'type', 'is_required', 'is_active', 'sort_order']));

            return $checkoutField;
        });

        return response()->json(['data' => $checkoutField], 201);
    }

    public function update(SaveCheckoutFieldRequest $request, CheckoutField $checkoutField, StoreSettings $settings, RecordAuditEventAction $audit): JsonResponse
    {
        $actor = $this->actor($request);
        $payload = $request->validated();
        if ($checkoutField->is_system && array_intersect(array_keys($payload), ['key', 'type', 'is_required', 'is_active', 'options']) !== []) {
            throw ValidationException::withMessages(['field' => 'La clé, le type et les protections d’un champ système ne peuvent pas être modifiés.']);
        }
        DB::transaction(function () use ($settings, $audit, $checkoutField, $payload, $actor): void {
            $before = $checkoutField->only(array_keys($payload));
            $checkoutField->update($payload);
            $settings->incrementSchemaVersion($actor->id);
            $audit->handle('checkout.field_updated', $checkoutField, $actor, $before, $payload);
        });

        return response()->json(['data' => $checkoutField->fresh()]);
    }

    public function reorder(Request $request, StoreSettings $settings, RecordAuditEventAction $audit): JsonResponse
    {
        $actor = $this->actor($request);
        $payload = $request->validate(['items' => ['required', 'array', 'min:1', 'max:100'], 'items.*.public_id' => ['required', 'ulid', 'distinct'], 'items.*.sort_order' => ['required', 'integer', 'between:0,1000']]);
        DB::transaction(function () use ($payload, $settings, $audit, $actor): void {
            foreach ($payload['items'] as $position) {
                CheckoutField::query()->where('public_id', $position['public_id'])->update(['sort_order' => $position['sort_order']]);
            }
            $settings->incrementSchemaVersion($actor->id);
            $field = CheckoutField::query()->where('public_id', $payload['items'][0]['public_id'])->firstOrFail();
            $audit->handle('checkout.fields_reordered', $field, $actor, after: ['count' => count($payload['items'])]);
        });

        return response()->json(['data' => null]);
    }

    public function destroy(Request $request, CheckoutField $checkoutField, StoreSettings $settings, RecordAuditEventAction $audit): JsonResponse
    {
        $actor = $this->actor($request);
        if ($checkoutField->is_system) {
            throw ValidationException::withMessages(['field' => 'Un champ système ne peut pas être supprimé.']);
        }
        DB::transaction(function () use ($settings, $audit, $checkoutField, $actor): void {
            $audit->handle('checkout.field_deleted', $checkoutField, $actor, before: $checkoutField->only(['key', 'label']));
            $checkoutField->delete();
            $settings->incrementSchemaVersion($actor->id);
        });

        return response()->json(['data' => null]);
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
