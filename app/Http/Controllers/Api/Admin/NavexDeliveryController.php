<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Navex\Enums\NavexDeliveryStatus;
use App\Domain\Navex\Models\NavexShipment;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NavexDeliveryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'in:'.implode(',', array_map(fn (NavexDeliveryStatus $status): string => $status->value, NavexDeliveryStatus::cases()))],
            'synchronized' => ['nullable', 'boolean'],
            'action_required' => ['nullable', 'boolean'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);
        $shipments = NavexShipment::query()
            ->with('order:id,public_reference,customer_name,status')
            ->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when(array_key_exists('synchronized', $data), fn ($query) => $data['synchronized'] ? $query->whereNotNull('last_synchronized_at') : $query->whereNull('last_synchronized_at'))
            ->when(($data['action_required'] ?? false) === true, fn ($query) => $query->whereIn('status', [NavexDeliveryStatus::ManualActionRequired->value, NavexDeliveryStatus::UncertainResult->value]))
            ->when($data['date_from'] ?? null, fn ($query, $date) => $query->whereDate('created_at', '>=', $date))
            ->when($data['date_to'] ?? null, fn ($query, $date) => $query->whereDate('created_at', '<=', $date))
            ->latest('updated_at')
            ->paginate($data['per_page'] ?? 25);

        $summary = [
            'pending_send' => NavexShipment::query()->where('status', NavexDeliveryStatus::PendingSend)->count(),
            'in_delivery' => NavexShipment::query()->where('status', NavexDeliveryStatus::InDelivery)->count(),
            'delivered_today' => NavexShipment::query()
                ->where('status', NavexDeliveryStatus::DeliveredPaid)
                ->whereDate('provider_status_at', now()->toDateString())
                ->count(),
            'returned' => NavexShipment::query()->where('status', NavexDeliveryStatus::Returned)->count(),
            'action_required' => NavexShipment::query()
                ->whereIn('status', [NavexDeliveryStatus::ManualActionRequired, NavexDeliveryStatus::UncertainResult])
                ->count(),
        ];

        return response()->json(['data' => $shipments, 'meta' => ['summary' => $summary]]);
    }
}
