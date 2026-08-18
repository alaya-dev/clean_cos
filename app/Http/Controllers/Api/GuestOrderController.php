<?php

namespace App\Http\Controllers\Api;

use App\Domain\Commerce\Actions\CreateGuestOrderAction;
use App\Domain\Commerce\Exceptions\CheckoutConflictException;
use App\Domain\MetaTracking\Models\MetaEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\CreateGuestOrderRequest;
use App\Http\Resources\PublicOrderResource;
use App\Http\Responses\ApiResponse;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;

class GuestOrderController extends Controller
{
    public function __invoke(CreateGuestOrderRequest $request, CreateGuestOrderAction $orders): JsonResponse
    {
        $key = (string) $request->header('Idempotency-Key');
        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $key)) {
            return ApiResponse::error('VALIDATION_ERROR', 'La demande de commande est invalide.', 422);
        }
        $lock = Cache::lock('pc:checkout:'.$key, 15);
        if (! $lock->get()) {
            return ApiResponse::error('CHECKOUT_IN_PROGRESS', 'Votre commande est en cours de traitement. Réessayez dans un instant.', 409, meta: ['request_id' => $request->attributes->get('request_id')]);
        }
        try {
            $result = $orders->handle($request->validated(), $key);
        } catch (CheckoutConflictException $exception) {
            return ApiResponse::error($exception->codeName, $exception->getMessage(), 409, meta: ['request_id' => $request->attributes->get('request_id')]);
        } finally {
            $lock->release();
        }
        $order = $result['order'];
        $purchase = MetaEvent::query()
            ->where('order_id', $order->id)
            ->where('event_name', 'Purchase')
            ->first();

        $expiresAt = now()->addDays(7);

        return ApiResponse::success(['order' => (new PublicOrderResource($order))->toArray($request), 'confirmation' => ['url' => URL::temporarySignedRoute('storefront.confirmation', $expiresAt, ['order' => $order]), 'expires_at' => $expiresAt->toIso8601String()], 'meta' => ['browser_purchase_required' => $purchase !== null, 'browser_event' => $this->browserEvent($purchase)]], ['request_id' => $request->attributes->get('request_id')], $result['replayed'] ? 200 : 201);
    }

    /** @return array<string, mixed>|null */
    private function browserEvent(?MetaEvent $event): ?array
    {
        if (! $event) {
            return null;
        }
        $eventTime = $event->getAttribute('event_time');
        if (! $eventTime instanceof CarbonInterface) {
            return null;
        }

        return ['public_id' => $event->public_id, 'event_name' => $event->event_name, 'event_id' => $event->event_id, 'event_time' => $eventTime->toIso8601String(), 'source_url' => $event->source_url, 'context' => $event->context_summary];
    }
}
