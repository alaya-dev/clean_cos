<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Audit\Actions\RecordAuditEventAction;
use App\Domain\Catalog\Models\Product;
use App\Domain\Checkout\Support\TunisianGovernorates;
use App\Domain\Commerce\Actions\CreateManualOrderAction;
use App\Domain\Commerce\Actions\PermanentlyDeleteArchivedOrdersAction;
use App\Domain\Commerce\Actions\ReconcileOrderItemsAction;
use App\Domain\Commerce\Actions\TransitionOrderStatusAction;
use App\Domain\Commerce\Actions\UpdateOrderCustomerAction;
use App\Domain\Commerce\Actions\UpdateOrderTotalAction;
use App\Domain\Commerce\Exceptions\CheckoutConflictException;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\OrderChangeEvent;
use App\Domain\Commerce\Models\OrderItem;
use App\Domain\Commerce\Support\OrderStatusFlow;
use App\Domain\FirstDelivery\Services\FirstDeliveryShipmentService;
use App\Domain\Navex\Enums\NavexDeliveryStatus;
use App\Domain\Navex\Services\NavexShipmentPayloadFactory;
use App\Domain\Navex\Services\NavexShipmentService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OrderController extends Controller
{
    public function store(Request $request, CreateManualOrderAction $action, RecordAuditEventAction $audit): JsonResponse
    {
        $data = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            'checkout_schema_version' => ['required', 'string', 'size:64'],
            'customer' => ['required', 'array'],
            'customer.*' => ['nullable'],
            'customer.first_delivery_locality_id' => ['nullable', 'integer', 'exists:first_delivery_localities,locality_id'],
            'exchange' => ['sometimes', 'array'],
            'exchange.is_exchange' => ['sometimes'],
            'exchange.article_designation' => ['nullable', 'string', 'max:500'],
            'exchange.article_count' => ['nullable'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_public_id' => ['required', 'ulid'],
            'items.*.variant_public_id' => ['nullable', 'ulid'],
            'items.*.quantity' => ['required', 'integer', 'between:1,99'],
            'status' => ['nullable', Rule::in(array_diff(OrderStatusFlow::STATUSES, ['annulee']))],
            'manual_total_millimes' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:999999999999999'],
        ]);
        $actor = $request->user();
        if ($actor === null) {
            abort(401);
        }

        $lock = Cache::lock('pc:manual-order:'.$data['idempotency_key'], 15);
        if (! $lock->get()) {
            return response()->json(['code' => 'MANUAL_ORDER_IN_PROGRESS', 'message' => 'Cette commande est en cours de création. Réessayez dans un instant.'], 409);
        }
        try {
            try {
                $order = $action->handle($data, $actor);
            } catch (CheckoutConflictException $exception) {
                return response()->json(['code' => $exception->codeName, 'message' => $exception->getMessage()], 409);
            }
        } finally {
            $lock->release();
        }
        if ($order->wasRecentlyCreated) {
            $audit->handle('order.manually_created', $order, $actor, after: [
                'status' => $order->status,
                'item_count' => $order->items->sum('quantity'),
                'total_millimes' => $order->total_millimes,
                'marketing_consent' => true,
            ]);
        }

        return response()->json(['data' => [
            'order' => $order,
            'meta_purchase_queued' => true,
        ]], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['search' => ['nullable', 'string', 'max:180'], 'product_public_id' => ['nullable', 'ulid'], 'status' => ['nullable', Rule::in(OrderStatusFlow::STATUSES)], 'statuses' => ['nullable', 'array', 'max:3'], 'statuses.*' => [Rule::in(OrderStatusFlow::STATUSES)], 'navex_status' => ['nullable', 'in:'.implode(',', array_map(fn (NavexDeliveryStatus $status): string => $status->value, NavexDeliveryStatus::cases()))], 'navex_sent' => ['nullable', 'boolean'], 'navex_action_required' => ['nullable', 'boolean'], 'delivery_provider' => ['nullable', Rule::in(['navex', 'first_delivery'])], 'archived' => ['nullable', 'boolean'], 'date_from' => ['nullable', 'date'], 'date_to' => ['nullable', 'date'], 'min_total_millimes' => ['nullable', 'integer', 'min:0'], 'max_total_millimes' => ['nullable', 'integer', 'min:0'], 'sort' => ['nullable', 'in:created_at,-created_at,total_millimes,-total_millimes,status,customer_name'], 'per_page' => ['nullable', 'integer', 'between:1,100']]);
        $query = Order::query()->withCount('items')->with([
            'navexShipment:id,order_id,status,tracking_code,raw_status,last_synchronized_at,last_error_classification',
            'firstDeliveryShipment:id,order_id,local_status,barcode,remote_state_code,remote_state,last_synced_at,last_error',
            'items.product.images' => fn ($images) => $images
                ->select(['id', 'product_id', 'path', 'renditions', 'processing_status', 'is_primary', 'sort_order'])
                ->where('is_primary', true),
        ]);
        ($data['archived'] ?? false) ? $query->whereNotNull('archived_at') : $query->whereNull('archived_at');
        if (($data['delivery_provider'] ?? null) === 'navex') {
            $query->whereHas('navexShipment');
        } elseif (($data['delivery_provider'] ?? null) === 'first_delivery') {
            $query->whereHas('firstDeliveryShipment');
        }
        if ($data['navex_status'] ?? null) {
            $query->whereHas('navexShipment', fn ($shipment) => $shipment->where('status', $data['navex_status']));
        }
        if (array_key_exists('navex_sent', $data)) {
            $query->whereHas('navexShipment', fn ($shipment) => $data['navex_sent'] ? $shipment->whereNotNull('tracking_code') : $shipment->whereNull('tracking_code'));
        }
        if (($data['navex_action_required'] ?? false) === true) {
            $query->whereHas('navexShipment', fn ($shipment) => $shipment->whereIn('status', [NavexDeliveryStatus::ManualActionRequired->value, NavexDeliveryStatus::UncertainResult->value]));
        }
        if ($data['search'] ?? null) {
            $query->where(fn ($q) => $q
                ->where('public_reference', 'like', '%'.$data['search'].'%')
                ->orWhere('customer_name', 'like', '%'.$data['search'].'%')
                ->orWhere('customer_phone', 'like', '%'.$data['search'].'%')
                ->orWhereHas('items', fn ($items) => $items
                    ->where('product_name_snapshot', 'like', '%'.$data['search'].'%')
                    ->orWhereHas('product', fn ($product) => $product->where('name', 'like', '%'.$data['search'].'%'))));
        }
        if ($data['product_public_id'] ?? null) {
            $query->whereHas('items.product', fn ($product) => $product->where('public_id', $data['product_public_id']));
        }
        if (! empty($data['statuses'])) {
            $query->whereIn('status', $data['statuses']);
        }
        foreach (['status', 'date_from', 'date_to', 'min_total_millimes', 'max_total_millimes'] as $filter) {
            if (isset($data[$filter])) {
                if ($filter === 'status' && ! empty($data['statuses'])) {
                    continue;
                }
                match ($filter) {
                    'date_from' => $query->whereDate('created_at', '>=', $data[$filter]), 'date_to' => $query->whereDate('created_at', '<=', $data[$filter]), 'min_total_millimes' => $query->where('total_millimes', '>=', $data[$filter]), 'max_total_millimes' => $query->where('total_millimes', '<=', $data[$filter]), default => $query->where($filter, $data[$filter])
                };
            }
        }
        $sort = $data['sort'] ?? '-created_at';
        $query->orderBy(ltrim($sort, '-'), str_starts_with($sort, '-') ? 'desc' : 'asc');

        $orders = $query->paginate($data['per_page'] ?? 25);
        $orders->getCollection()->transform(function (Order $order): Order {
            $order->setAttribute('product_thumbnail_url', $order->items->first()?->product?->images->first()?->public_url);
            $order->setAttribute('is_returning_customer', $order->customer_id !== null && $order->customer_previous_order_at !== null);
            $order->setAttribute('product_names', $order->items
                ->map(fn ($item): ?string => $item->product_name_snapshot ?: $item->product?->name)
                ->filter()
                ->unique()
                ->values()
                ->all());
            $navexShipment = $order->navexShipment;
            $firstDeliveryShipment = $order->firstDeliveryShipment;
            $order->setAttribute('navex_delivery', $navexShipment ? ['status' => $navexShipment->status->value, 'label' => $navexShipment->display_status_label, 'tracking_code' => $navexShipment->tracking_code] : null);
            $order->setAttribute('delivery', $firstDeliveryShipment ? [
                'provider' => 'first_delivery',
                'provider_label' => 'First Delivery',
                'status' => $firstDeliveryShipment->local_status->value,
                'label' => $firstDeliveryShipment->local_status->label(),
                'tracking_code' => $firstDeliveryShipment->barcode,
            ] : ($navexShipment ? [
                'provider' => 'navex',
                'provider_label' => 'Navex',
                'status' => $navexShipment->status->value,
                'label' => $navexShipment->display_status_label,
                'tracking_code' => $navexShipment->tracking_code,
            ] : null));
            $order->unsetRelation('firstDeliveryShipment');
            $order->unsetRelation('items');

            return $order;
        });

        return response()->json(['data' => $orders, 'meta' => [
            'order_changes_cursor' => $this->encodeChangeCursor((int) (OrderChangeEvent::query()->max('id') ?? 0)),
            'new_orders_count' => Order::query()->whereNull('archived_at')->where('status', 'nouvelle')->count(),
        ]]);
    }

    public function customerHistory(Order $order): JsonResponse
    {
        if ($order->customer_id === null) {
            return response()->json(['data' => ['orders' => [], 'has_more' => false]]);
        }

        $history = Order::query()
            ->where('customer_id', $order->customer_id)
            ->whereKeyNot($order->getKey())
            ->where(function ($query) use ($order): void {
                $query
                    ->where('created_at', '<', $order->created_at)
                    ->orWhere(function ($sameTime) use ($order): void {
                        $sameTime
                            ->where('created_at', $order->created_at)
                            ->where('id', '<', $order->getKey());
                    });
            })
            ->with('items:id,order_id,product_name_snapshot,quantity')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(6)
            ->get(['id', 'public_reference', 'status', 'created_at']);

        return response()->json(['data' => [
            'orders' => $history->take(5)->values()->map(function (Order $previous, int $index): array {
                return [
                    'number' => $index + 1,
                    'public_reference' => $previous->public_reference,
                    'status' => $previous->status,
                    'created_at' => $previous->created_at?->toAtomString(),
                    'items' => $previous->items
                        ->map(fn ($item): array => ['name' => $item->product_name_snapshot, 'quantity' => $item->quantity])
                        ->values()
                        ->all(),
                ];
            })->all(),
            'has_more' => $history->count() > 5,
        ]]);
    }

    public function changes(Request $request): JsonResponse
    {
        $data = $request->validate(['cursor' => ['nullable', 'string', 'max:128']]);
        try {
            $after = $this->decodeChangeCursor($data['cursor'] ?? null);
        } catch (\InvalidArgumentException) {
            return response()->json(['code' => 'INVALID_ORDER_CHANGE_CURSOR', 'message' => 'Le curseur de commandes est invalide.'], 422);
        }

        $events = OrderChangeEvent::query()
            ->where('id', '>', $after)
            ->orderBy('id')
            ->limit(500)
            ->get(['id', 'order_public_reference', 'change_type']);
        $next = $events->isEmpty() ? $after : (int) $events->last()->id;

        if ($events->isEmpty()) {
            return response()->json(['data' => ['changed' => false, 'cursor' => $this->encodeChangeCursor($next)]]);
        }

        $references = static fn (string $type): array => $events
            ->where('change_type', $type)
            ->pluck('order_public_reference')
            ->unique()
            ->values()
            ->all();
        $statusCounts = Order::query()
            ->whereNull('archived_at')
            ->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        return response()->json(['data' => [
            'changed' => true,
            'cursor' => $this->encodeChangeCursor($next),
            'created_ids' => $references('created'),
            'updated_ids' => $references('updated'),
            'deleted_ids' => $references('deleted'),
            'counts' => [
                'new' => (int) ($statusCounts['nouvelle'] ?? 0),
                'confirmed' => (int) ($statusCounts['confirmee'] ?? 0),
                'cancelled' => (int) ($statusCounts['annulee'] ?? 0),
                'attempt_1' => (int) ($statusCounts['tentative_1'] ?? 0),
                'attempt_2' => (int) ($statusCounts['tentative_2'] ?? 0),
                'attempt_3' => (int) ($statusCounts['tentative_3'] ?? 0),
            ],
        ]]);
    }

    public function show(Order $order, NavexShipmentService $navex, FirstDeliveryShipmentService $firstDelivery, NavexShipmentPayloadFactory $payloads): JsonResponse
    {
        $order->load([
            'items.product.variants.values',
            'items.product.images' => fn ($images) => $images
                ->select(['id', 'product_id', 'path', 'renditions', 'processing_status', 'is_primary', 'sort_order'])
                ->where('is_primary', true),
            'items.variant.values',
            'checkoutValues',
            'customer',
            'statusHistory.changedBy:id,public_id,name,role',
            'notes',
            'navexShipment.statusHistory',
            'firstDeliveryShipment.statusHistory',
            'firstDeliveryLocality',
        ]);

        $shipment = $order->navexShipment;
        $shipmentTracked = $shipment?->hasTrackingCode() ?? false;
        $firstDeliveryShipment = $order->firstDeliveryShipment;

        $order->setAttribute('designation', $payloads->designation($order));

        return response()->json(['data' => [
            'order' => $order,
            'is_editable' => OrderStatusFlow::canEditItems($order->status),
            'is_delivery_editable' => true,
            'allowed_transitions' => $this->transitions($order->status),
            'navex' => [
                'ready' => $navex->ready($order),
                'shipment' => $shipment,
                'manual_update_required' => $shipmentTracked,
            ],
            'first_delivery' => [
                'ready' => $firstDelivery->ready($order),
                'shipment' => $firstDeliveryShipment,
                'manual_update_required' => filled($firstDeliveryShipment?->barcode),
            ],
            'meta_purchase' => ['event_id' => $order->meta_event_id, 'status' => 'not_configured'],
        ]]);
    }

    public function availableProducts(Request $request): JsonResponse
    {
        $data = $request->validate(['search' => ['nullable', 'string', 'max:120']]);
        $products = Product::query()
            ->public()
            ->select(['id', 'public_id', 'name', 'has_variants', 'stock_quantity'])
            ->when($data['search'] ?? null, fn ($query, string $search) => $query->where('name', 'like', '%'.$search.'%'))
            ->with(['allVariants' => fn ($variants) => $variants
                ->where('is_active', true)
                ->select(['id', 'public_id', 'product_id', 'stock_quantity', 'is_active', 'is_default'])
                ->with('values:id,value'),
                'images' => fn ($images) => $images
                    ->select(['id', 'product_id', 'path', 'renditions', 'processing_status', 'is_primary', 'sort_order'])
                    ->where('is_primary', true),
            ])
            ->orderBy('name')
            ->limit(50)
            ->get()
            ->each(function (Product $product): void {
                $product->setRelation('variants', $product->allVariants);
                $product->unsetRelation('allVariants');
            });

        return response()->json(['data' => $products]);
    }

    public function transition(Request $request, Order $order, TransitionOrderStatusAction $action): JsonResponse
    {
        $data = $request->validate(['to_status' => ['required', Rule::in(OrderStatusFlow::STATUSES)], 'reason' => ['nullable', 'string', 'max:500'], 'lock_version' => ['required', 'integer', 'min:1']]);
        if ($data['lock_version'] !== $order->lock_version) {
            return response()->json(['code' => 'ORDER_VERSION_CONFLICT', 'message' => 'La commande a été modifiée. Actualisez-la avant de continuer.'], 409);
        }

        $actor = $request->user();
        if ($actor === null) {
            abort(401);
        }

        return response()->json(['data' => $action->handle($order, $data['to_status'], $data['reason'] ?? null, $actor->id)]);
    }

    public function update(Request $request, Order $order, UpdateOrderCustomerAction $action, RecordAuditEventAction $audit): JsonResponse
    {
        $data = $request->validate(['lock_version' => ['required', 'integer', 'min:1'], 'customer.full_name' => ['required', 'string', 'between:2,180'], 'customer.phone' => ['required', 'string', 'max:40'], 'customer.city' => ['required', 'string', 'between:2,160'], 'customer.governorate' => ['nullable', 'string', 'between:2,80', Rule::in(TunisianGovernorates::ALL)], 'customer.first_delivery_locality_id' => ['nullable', 'integer', 'exists:first_delivery_localities,locality_id'], 'customer.address' => ['required', 'string', 'between:5,2000'], 'customer.is_exchange' => ['sometimes'], 'customer.exchange_article_designation' => ['nullable', 'string', 'max:500'], 'customer.exchange_article_count' => ['nullable'], 'exchange' => ['sometimes', 'array'], 'exchange.is_exchange' => ['sometimes'], 'exchange.article_designation' => ['nullable', 'string', 'max:500'], 'exchange.article_count' => ['nullable']]);
        try {
            $before = $order->only(['customer_name', 'customer_phone', 'customer_city', 'customer_governorate', 'first_delivery_locality_id', 'customer_address', 'is_exchange', 'exchange_article_designation', 'exchange_article_count']);
            $exchange = null;
            if (array_key_exists('exchange', $data)) {
                $exchange = $data['exchange'];
            } elseif (array_key_exists('is_exchange', $data['customer'])
                || array_key_exists('exchange_article_designation', $data['customer'])
                || array_key_exists('exchange_article_count', $data['customer'])) {
                $exchange = [
                    'is_exchange' => $data['customer']['is_exchange'] ?? false,
                    'article_designation' => $data['customer']['exchange_article_designation'] ?? null,
                    'article_count' => $data['customer']['exchange_article_count'] ?? null,
                ];
            }
            $result = $action->handle($order, $data['lock_version'], $data['customer'], $exchange);
            $fresh = $order->fresh();
            abort_unless($fresh !== null, 500);
            $audit->handle('order.customer_updated', $fresh, $request->user(), before: $before, after: $fresh->only(array_keys($before)));

            return response()->json(['data' => $result]);
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            $message = collect($errors)->flatten()->first() ?? 'La commande ne peut pas Ãªtre modifiÃ©e.';

            return response()->json(['code' => array_key_exists('lock_version', $errors) ? 'ORDER_VERSION_CONFLICT' : 'ORDER_NOT_EDITABLE', 'message' => $message], 409);
        }
    }

    public function updateItems(Request $request, Order $order, ReconcileOrderItemsAction $action, RecordAuditEventAction $audit): JsonResponse
    {
        $data = $request->validate(['lock_version' => ['required', 'integer', 'min:1'], 'items' => ['required', 'array', 'min:1', 'max:100'], 'items.*.product_public_id' => ['required', 'ulid'], 'items.*.variant_public_id' => ['nullable', 'ulid'], 'items.*.quantity' => ['required', 'integer', 'between:1,99'], 'remove_unavailable_item_ids' => ['sometimes', 'array', 'max:100'], 'remove_unavailable_item_ids.*' => ['integer', 'distinct']]);
        $actor = $request->user();
        if ($actor === null) {
            abort(401);
        }
        $beforeItems = $order->items()->get();
        try {
            $result = $action->handle($order, $data['lock_version'], $data['items'], $actor->id, $data['remove_unavailable_item_ids'] ?? []);
            $audit->handle(
                'order.items_updated',
                $result,
                $actor,
                before: ['items' => $this->itemAuditSnapshot($beforeItems)],
                after: ['items' => $this->itemAuditSnapshot($result->items), 'item_count' => count($data['items'])],
            );

            return response()->json(['data' => $result]);
        } catch (ValidationException $exception) {
            return response()->json(['code' => 'ORDER_UPDATE_CONFLICT', 'message' => $exception->getMessage()], 409);
        }
    }

    public function updateTotal(Request $request, Order $order, UpdateOrderTotalAction $action, RecordAuditEventAction $audit): JsonResponse
    {
        $data = $request->validate([
            'lock_version' => ['required', 'integer', 'min:1'],
            'total_millimes' => ['present', 'nullable', 'integer', 'min:0', 'max:999999999999999'],
        ]);
        $before = $order->only(['total_millimes', 'manual_total_millimes']);

        try {
            $result = $action->handle($order, $data['lock_version'], $data['total_millimes']);
        } catch (ValidationException $exception) {
            $errors = $exception->errors();

            return response()->json([
                'code' => array_key_exists('lock_version', $errors) ? 'ORDER_VERSION_CONFLICT' : 'ORDER_TOTAL_INVALID',
                'message' => collect($errors)->flatten()->first() ?? 'Le total de la commande ne peut pas être modifié.',
            ], 409);
        }

        $audit->handle('order.total_updated', $result, $request->user(), before: $before, after: $result->only(['total_millimes', 'manual_total_millimes']));

        return response()->json(['data' => $result]);
    }

    public function storeNote(Request $request, Order $order, RecordAuditEventAction $audit): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'between:1,5000']]);
        $actor = $request->user();
        if ($actor === null) {
            abort(401);
        }

        $note = $order->notes()->create(['user_id' => $actor->id, 'body' => $data['body'], 'created_at' => now()]);
        $audit->handle('order.note_added', $order, $actor, after: ['note_id' => $note->getKey()]);

        return response()->json(['data' => $note], 201);
    }

    public function bulkArchive(Request $request, RecordAuditEventAction $audit): JsonResponse
    {
        $data = $request->validate(['references' => ['required', 'array', 'min:1', 'max:100'], 'references.*' => ['ulid', 'distinct']]);
        $archived = DB::transaction(function () use ($data): int {
            $orders = Order::query()->whereIn('public_reference', $data['references'])->whereNull('archived_at')->lockForUpdate()->get();
            abort_if($orders->count() !== count($data['references']), 404);

            $orders->each->update(['archived_at' => now()]);

            return $orders->count();
        });

        $orders = Order::query()->whereIn('public_reference', $data['references'])->get(['id', 'public_reference', 'archived_at']);
        foreach ($orders as $order) {
            $archivedAt = $order->getRawOriginal('archived_at');
            $audit->handle('order.archived', $order, $request->user(), before: ['archived' => false], after: ['archived' => true, 'archived_at' => $archivedAt]);
        }
        $audit->handle('order.bulk_archived', $orders->firstOrFail(), $request->user(), after: ['count' => $archived, 'references' => $orders->pluck('public_reference')->values()->all()]);

        return response()->json(['data' => ['archived' => $archived]]);
    }

    public function bulkRestore(Request $request, RecordAuditEventAction $audit): JsonResponse
    {
        $data = $request->validate(['references' => ['required', 'array', 'min:1', 'max:100'], 'references.*' => ['ulid', 'distinct']]);
        $restored = DB::transaction(function () use ($data): int {
            $orders = Order::query()->whereIn('public_reference', $data['references'])->whereNotNull('archived_at')->lockForUpdate()->get();
            abort_if($orders->count() !== count($data['references']), 404);
            $orders->each->update(['archived_at' => null]);

            return $orders->count();
        });

        $orders = Order::query()->whereIn('public_reference', $data['references'])->get(['id', 'public_reference', 'archived_at']);
        foreach ($orders as $order) {
            $audit->handle('order.restored', $order, $request->user(), before: ['archived' => true], after: ['archived' => false]);
        }
        $audit->handle('order.bulk_restored', $orders->firstOrFail(), $request->user(), after: ['count' => $restored, 'references' => $orders->pluck('public_reference')->values()->all()]);

        return response()->json(['data' => ['restored' => $restored]]);
    }

    public function bulkDestroy(Request $request, PermanentlyDeleteArchivedOrdersAction $action): JsonResponse
    {
        $data = $request->validate(['references' => ['required', 'array', 'min:1', 'max:100'], 'references.*' => ['ulid', 'distinct']]);

        try {
            return response()->json(['data' => $action->handle($data['references'], $request->user())]);
        } catch (ValidationException $exception) {
            $message = collect($exception->errors())->flatten()->first() ?? 'Suppression définitive impossible.';

            return response()->json(['code' => array_key_exists('archived', $exception->errors()) ? 'ORDER_DELETE_ARCHIVE_REQUIRED' : 'ORDER_DELETE_META_DELIVERY_PENDING', 'message' => $message], 422);
        }
    }

    public function bulkTransition(Request $request, TransitionOrderStatusAction $action, RecordAuditEventAction $audit): JsonResponse
    {
        $data = $request->validate(['references' => ['required', 'array', 'min:1', 'max:100'], 'references.*' => ['ulid', 'distinct'], 'to_status' => ['required', Rule::in(OrderStatusFlow::STATUSES)], 'reason' => ['nullable', 'string', 'max:500']]);
        $actor = $request->user();
        if ($actor === null) {
            abort(401);
        }

        $updated = DB::transaction(function () use ($data, $action, $actor): int {
            $orders = Order::query()->whereIn('public_reference', $data['references'])->whereNull('archived_at')->lockForUpdate()->get();
            abort_if($orders->count() !== count($data['references']), 404);

            foreach ($orders as $order) {
                if ($order->status !== $data['to_status'] && ! OrderStatusFlow::canTransition($order->status, $data['to_status'])) {
                    throw ValidationException::withMessages(['to_status' => 'Toutes les commandes sélectionnées doivent permettre ce changement de statut.']);
                }
            }

            $changed = 0;
            foreach ($orders as $order) {
                if ($order->status !== $data['to_status']) {
                    $action->handle($order, $data['to_status'], $data['reason'] ?? ($data['to_status'] === 'annulee' ? 'Action groupée opérateur' : null), $actor->id);
                    $changed++;
                }
            }

            return $changed;
        });

        $audit->handle('order.bulk_transitioned', Order::query()->whereIn('public_reference', $data['references'])->firstOrFail(), $actor, after: ['count' => $updated, 'to_status' => $data['to_status']]);

        return response()->json(['data' => ['updated' => $updated, 'skipped' => 0]]);
    }

    public function export(Request $request): StreamedResponse
    {
        $data = $request->validate(['status' => ['nullable', Rule::in(OrderStatusFlow::STATUSES)], 'date_from' => ['nullable', 'date'], 'date_to' => ['nullable', 'date']]);
        $query = Order::query()->select(['id', 'public_reference', 'status', 'customer_name', 'customer_phone', 'customer_city', 'total_millimes', 'created_at'])->orderByDesc('created_at')->limit(10_000);
        if ($data['status'] ?? null) {
            $query->where('status', $data['status']);
        }
        if ($data['date_from'] ?? null) {
            $query->whereDate('created_at', '>=', $data['date_from']);
        }
        if ($data['date_to'] ?? null) {
            $query->whereDate('created_at', '<=', $data['date_to']);
        }

        return response()->streamDownload(function () use ($query): void {
            $handle = fopen('php://output', 'wb');
            if ($handle === false) {
                return;
            } fputcsv($handle, ['Référence', 'Statut', 'Client', 'Téléphone', 'Ville', 'Total millimes', 'Créée le']);
            $query->chunkById(500, function ($orders) use ($handle): void {
                foreach ($orders as $order) {
                    fputcsv($handle, [$order->public_reference, $order->status, $order->customer_name, $order->customer_phone, $order->customer_city, $order->total_millimes, $order->created_at?->toIso8601String()]);
                }
            });
            fclose($handle);
        }, 'commandes.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    /** @return array<int, string> */
    private function transitions(string $status): array
    {
        return OrderStatusFlow::transitions($status);
    }

    private function encodeChangeCursor(int $sequence): string
    {
        return rtrim(strtr(base64_encode(json_encode(['v' => 1, 'sequence' => max(0, $sequence)], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    private function decodeChangeCursor(?string $cursor): int
    {
        if ($cursor === null || $cursor === '') {
            return 0;
        }
        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
        if ($decoded === false) {
            throw new \InvalidArgumentException('Invalid cursor.');
        }
        $payload = json_decode($decoded, true);
        if (! is_array($payload) || ($payload['v'] ?? null) !== 1 || ! is_int($payload['sequence'] ?? null) || $payload['sequence'] < 0) {
            throw new \InvalidArgumentException('Invalid cursor.');
        }

        return $payload['sequence'];
    }

    /** @param iterable<int, OrderItem> $items
     * @return list<array{product: string|null, variant: list<string>, quantity: int, unit_price_millimes: int}>
     */
    private function itemAuditSnapshot(iterable $items): array
    {
        $snapshot = [];
        foreach ($items as $item) {
            $variantValues = [];
            $rawVariantSnapshot = $item->getRawOriginal('variant_snapshot');
            $variantSnapshot = is_string($rawVariantSnapshot) ? json_decode($rawVariantSnapshot, true) : null;
            if (is_array($variantSnapshot)) {
                foreach ($variantSnapshot as $variant) {
                    if (is_array($variant) && is_string($variant['value'] ?? null) && $variant['value'] !== '') {
                        $variantValues[] = $variant['value'];
                    }
                }
            }
            $snapshot[] = [
                'product' => $item->product_name_snapshot,
                'variant' => $variantValues,
                'quantity' => (int) $item->quantity,
                'unit_price_millimes' => (int) $item->effective_unit_price_millimes,
            ];
        }

        return $snapshot;
    }
}
