<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\OrderChangeEvent;
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
        $changeSequence = (int) (OrderChangeEvent::query()->max('id') ?? 0);
        $key = 'pc:dashboard:v3:'.$from->format('YmdHis').':'.$until->format('YmdHis').':'.$changeSequence;
        $data = Cache::remember($key, now()->addSeconds(60), fn (): array => $this->metrics($from, $until));

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
    private function metrics(CarbonImmutable $from, CarbonImmutable $until): array
    {
        $orders = Order::query()->whereBetween('created_at', [$from, $until]);
        $statusRows = (clone $orders)->select('status', DB::raw('COUNT(*) as total'))->groupBy('status')->pluck('total', 'status')->all();
        $delivered = (clone $orders)->whereHas('navexShipment', fn ($shipment) => $shipment->where('status', NavexDeliveryStatus::DeliveredPaid->value));
        $now = CarbonImmutable::now('Africa/Tunis');

        return [
            'orders' => [
                'submitted' => (clone $orders)->count(),
                'by_status' => $statusRows,
                'delivered_orders' => (clone $delivered)->count(),
                'delivered_revenue_millimes' => (int) $delivered->sum('total_millimes'),
                'average_delivered_order_millimes' => (int) round((float) (clone $delivered)->avg('total_millimes')),
                'best_sellers' => DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')->where('orders.status', '!=', 'annulee')->whereBetween('orders.created_at', [$from, $until])->select('order_items.product_name_snapshot as name', DB::raw('SUM(order_items.quantity) as quantity'))->groupBy('order_items.product_name_snapshot')->orderByDesc('quantity')->limit(5)->get(),
                'summary' => [
                    'today' => $this->salesSummary($now->startOfDay()->utc(), $now->endOfDay()->utc()),
                    'week' => $this->salesSummary($now->startOfWeek()->utc(), $now->endOfDay()->utc()),
                    'month' => $this->salesSummary($now->startOfMonth()->utc(), $now->endOfDay()->utc()),
                    'all' => $this->salesSummary(null, null),
                ],
                'trend' => $this->trend($from, $until),
            ],
            'sales' => [
                'summary' => [
                    'today' => $this->salesSummary($now->startOfDay()->utc(), $now->endOfDay()->utc()),
                    'week' => $this->salesSummary($now->startOfWeek()->utc(), $now->endOfDay()->utc()),
                    'month' => $this->salesSummary($now->startOfMonth()->utc(), $now->endOfDay()->utc()),
                    'all' => $this->salesSummary(null, null),
                ],
                'trend' => $this->salesTrend($from, $until),
            ],
        ];
    }

    /** @return array{orders:int,total_millimes:int,product_millimes:int,shipping_millimes:int} */
    private function salesSummary(?CarbonImmutable $from, ?CarbonImmutable $until): array
    {
        $query = DB::table('orders')->where('status', 'confirmee');
        if ($from && $until) {
            $query->whereBetween('created_at', [$from, $until]);
        }

        $totals = (clone $query)->selectRaw('COUNT(*) as orders, COALESCE(SUM(total_millimes), 0) as total_millimes, COALESCE(SUM(shipping_fee_millimes), 0) as shipping_millimes')->first();
        $total = (int) ($totals->total_millimes ?? 0);
        $shipping = (int) ($totals->shipping_millimes ?? 0);

        return ['orders' => (int) ($totals->orders ?? 0), 'total_millimes' => $total, 'product_millimes' => max(0, $total - $shipping), 'shipping_millimes' => $shipping];
    }

    /** @return list<array{date:string,total_millimes:int}> */
    private function salesTrend(CarbonImmutable $from, CarbonImmutable $until): array
    {
        $rows = DB::table('orders')->where('status', 'confirmee')->whereBetween('created_at', [$from, $until])
            ->select(DB::raw('DATE(created_at) as day'), DB::raw('SUM(total_millimes) as total_millimes'))
            ->groupBy(DB::raw('DATE(created_at)'))->get()->keyBy(fn ($row): string => (string) $row->day);
        $start = $from->timezone('Africa/Tunis')->startOfDay();
        $end = $until->timezone('Africa/Tunis')->startOfDay();
        $points = [];
        for ($date = $start; $date->lessThanOrEqualTo($end); $date = $date->addDay()) {
            $row = $rows->get($date->toDateString());
            $points[] = ['date' => $date->toDateString(), 'total_millimes' => (int) ($row->total_millimes ?? 0)];
        }

        return $points;
    }

    /** @return list<array{date:string,orders:int,total_millimes:int}> */
    private function trend(CarbonImmutable $from, CarbonImmutable $until): array
    {
        $rows = DB::table('orders')->whereBetween('created_at', [$from, $until])->where('status', '!=', 'annulee')->select(DB::raw('DATE(created_at) as day'), DB::raw('COUNT(*) as orders'), DB::raw('SUM(total_millimes) as total_millimes'))->groupBy(DB::raw('DATE(created_at)'))->get()->keyBy(fn ($row): string => (string) $row->day);
        $draftRows = DB::table('checkout_drafts')->whereNull('converted_at')->where('last_activity_at', '<=', now()->subMinutes((int) config('checkout.draft_abandonment_minutes', 15)))->whereBetween('last_activity_at', [$from, $until])->select(DB::raw('DATE(last_activity_at) as day'), DB::raw('COUNT(*) as drafts'))->groupBy(DB::raw('DATE(last_activity_at)'))->get()->keyBy(fn ($row): string => (string) $row->day);
        $start = $from->timezone('Africa/Tunis')->startOfDay();
        $end = $until->timezone('Africa/Tunis')->startOfDay();
        $points = [];
        for ($date = $start; $date->lessThanOrEqualTo($end); $date = $date->addDay()) {
            $row = $rows->get($date->toDateString());
            $draftRow = $draftRows->get($date->toDateString());
            $points[] = ['date' => $date->toDateString(), 'orders' => (int) ($row->orders ?? 0), 'drafts' => (int) ($draftRow->drafts ?? 0), 'total_millimes' => (int) ($row->total_millimes ?? 0)];
        }

        return $points;
    }

    private function encodeChangeCursor(int $sequence): string
    {
        return rtrim(strtr(base64_encode(json_encode(['v' => 1, 'sequence' => max(0, $sequence)], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }
}
