<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\OrderChangeEvent;
use App\Domain\Complaints\Models\Complaint;
use App\Domain\MetaTracking\Models\MetaConfiguration;
use App\Domain\MetaTracking\Models\MetaEvent;
use App\Domain\Navex\Enums\NavexDeliveryStatus;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OperationalDashboardController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'period' => ['nullable', 'in:today,7d,30d,month,custom'],
            'date_from' => ['nullable', 'date_format:Y-m-d', 'required_if:period,custom'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'required_if:period,custom', 'after_or_equal:date_from'],
        ]);
        [$from, $until, $period] = $this->period($filters);
        $role = (string) $request->user()?->role;
        $key = 'pc:dashboard:v1:'.$role.':'.$from->format('YmdHis').':'.$until->format('YmdHis');

        $data = Cache::remember($key, now()->addSeconds(60), fn (): array => $this->metrics($from, $until, $role === 'super_admin'));

        return ApiResponse::success([
            'period' => $period,
            'timezone' => 'Africa/Tunis',
            'order_changes_cursor' => $this->encodeChangeCursor((int) (OrderChangeEvent::query()->max('id') ?? 0)),
            ...$data,
        ]);
    }

    /** @param array<string, mixed> $filters
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: string}
     */
    private function period(array $filters): array
    {
        $timezone = 'Africa/Tunis';
        $now = CarbonImmutable::now($timezone);
        $period = $filters['period'] ?? '7d';
        if ($period === 'custom') {
            $from = CarbonImmutable::createFromFormat('Y-m-d', (string) $filters['date_from'], $timezone);
            $until = CarbonImmutable::createFromFormat('Y-m-d', (string) $filters['date_to'], $timezone);
            if (! $from || ! $until) {
                throw ValidationException::withMessages(['period' => 'La période personnalisée est invalide.']);
            }
            $from = $from->startOfDay();
            $until = $until->endOfDay();
            abort_if($from->diffInDays($until) > 93, 422, 'La période personnalisée ne peut pas dépasser 93 jours.');

            return [$from->utc(), $until->utc(), $period];
        }
        $from = match ($period) {
            'today' => $now->startOfDay(),
            '30d' => $now->subDays(29)->startOfDay(),
            'month' => $now->startOfMonth(),
            default => $now->subDays(6)->startOfDay(),
        };

        return [$from->utc(), $now->endOfDay()->utc(), $period];
    }

    /** @return array<string, mixed> */
    private function metrics(CarbonImmutable $from, CarbonImmutable $until, bool $isSuperAdmin): array
    {
        $orders = Order::query()->whereBetween('created_at', [$from, $until]);
        $statusRows = (clone $orders)->select('status', DB::raw('COUNT(*) as total'))->groupBy('status')->pluck('total', 'status')->all();
        $delivered = (clone $orders)->whereHas('navexShipment', fn ($shipment) => $shipment->where('status', NavexDeliveryStatus::DeliveredPaid->value));
        $funnel = MetaEvent::query()->whereBetween('created_at', [$from, $until]);
        $eventCounts = (clone $funnel)->select('event_name', DB::raw('COUNT(*) as total'))->groupBy('event_name')->pluck('total', 'event_name')->all();
        $delivery = (clone $funnel)->select('capi_state', DB::raw('COUNT(*) as total'))->groupBy('capi_state')->pluck('total', 'capi_state')->all();
        $browserAttempts = (clone $funnel)->whereIn('browser_state', ['rendered', 'attempted'])->count();

        $data = [
            'orders' => [
                'submitted' => (clone $orders)->count(),
                'by_status' => $statusRows,
                'delivered_revenue_millimes' => (int) $delivered->sum('total_millimes'),
                'average_delivered_order_millimes' => (int) round((float) (clone $delivered)->avg('total_millimes')),
                'best_sellers' => DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')->join('navex_shipments', 'navex_shipments.order_id', '=', 'orders.id')->where('navex_shipments.status', NavexDeliveryStatus::DeliveredPaid->value)->whereBetween('orders.created_at', [$from, $until])->select('order_items.product_name_snapshot as name', DB::raw('SUM(order_items.quantity) as quantity'))->groupBy('order_items.product_name_snapshot')->orderByDesc('quantity')->limit(5)->get(),
            ],
            'inventory' => [
                'low_stock_products' => Product::query()->where('is_active', true)->whereColumn('stock_quantity', '<=', 'low_stock_threshold')->orderBy('stock_quantity')->limit(8)->get(['public_id', 'name', 'stock_quantity', 'low_stock_threshold']),
                'low_stock_variants' => ProductVariant::query()
                    ->with('product:id,name')
                    ->where('is_current', true)
                    ->where('is_active', true)
                    ->whereNotNull('low_stock_threshold')
                    ->whereColumn('stock_quantity', '<=', 'low_stock_threshold')
                    ->orderBy('stock_quantity')
                    ->limit(8)
                    ->get(['public_id', 'product_id', 'combination_key', 'stock_quantity', 'low_stock_threshold'])
                    ->map(fn (ProductVariant $variant): array => [
                        'public_id' => $variant->public_id,
                        'product_name' => $variant->product?->name,
                        'combination_key' => $variant->combination_key,
                        'stock_quantity' => $variant->stock_quantity,
                        'low_stock_threshold' => $variant->low_stock_threshold,
                    ]),
            ],
            'complaints' => Complaint::query()->whereBetween('created_at', [$from, $until])->latest()->limit(5)->get(['public_reference', 'status', 'created_at']),
            'meta' => [
                'tracking_available' => MetaConfiguration::query()->where('state', 'active')->where('tracking_enabled', true)->exists(),
                'logical_events' => $eventCounts,
                'pixel_attempts' => $browserAttempts,
                'capi' => $delivery,
                'purchases' => [
                    'successful_orders' => (clone $orders)->count(),
                    'consent_eligible' => (clone $funnel)->where('event_name', 'Purchase')->where('marketing_consent', true)->count(),
                    'pixel_attempts' => (clone $funnel)->where('event_name', 'Purchase')->whereIn('browser_state', ['rendered', 'attempted'])->count(),
                    'capi_delivered' => (clone $funnel)->where('event_name', 'Purchase')->where('capi_state', 'succeeded')->count(),
                    'pending' => (clone $funnel)->where('event_name', 'Purchase')->whereIn('capi_state', ['pending', 'sending', 'temporary_failure'])->count(),
                    'failed' => (clone $funnel)->where('event_name', 'Purchase')->where('capi_state', 'permanent_failure')->count(),
                    'matching_event_id_ready' => (clone $funnel)->where('event_name', 'Purchase')->whereNotNull('event_id')->count(),
                ],
            ],
        ];

        if (! $isSuperAdmin) {
            unset($data['meta']['capi']);
        }

        return $data;
    }

    private function encodeChangeCursor(int $sequence): string
    {
        return rtrim(strtr(base64_encode(json_encode(['v' => 1, 'sequence' => max(0, $sequence)], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }
}
