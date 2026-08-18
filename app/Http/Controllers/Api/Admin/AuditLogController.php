<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Commerce\Models\Order;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\Admin\AuditLogResource;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'actor_role' => ['nullable', 'in:admin,super_admin'],
            'scope' => ['nullable', 'in:orders,users'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);
        $logs = AuditLog::query()
            ->with('actor:id,public_id,name,role')
            ->where(fn ($query) => $query->where('action', 'like', 'order.%')->orWhere('action', 'like', 'user.%'))
            ->when(($filters['scope'] ?? null) === 'orders', fn ($query) => $query->where('action', 'like', 'order.%'))
            ->when(($filters['scope'] ?? null) === 'users', fn ($query) => $query->where('action', 'like', 'user.%'))
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->where(fn ($nested) => $nested
                ->where('action', 'like', '%'.$search.'%')
                ->orWhere('request_id', 'like', '%'.$search.'%')
                ->orWhere('auditable_id', 'like', '%'.$search.'%')
                ->orWhereHas('actor', fn ($actor) => $actor->where('name', 'like', '%'.$search.'%'))))
            ->when($filters['actor_role'] ?? null, fn ($query, $role) => $query->where('actor_role_snapshot', $role))
            ->when($filters['date_from'] ?? null, fn ($query, $date) => $query->whereDate('created_at', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($query, $date) => $query->whereDate('created_at', '<=', $date))
            ->latest('created_at')
            ->paginate($filters['per_page'] ?? 25);
        $this->attachAuditTargets($logs->getCollection());

        return ApiResponse::success([
            'data' => AuditLogResource::collection($logs->getCollection())->resolve(),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'total' => $logs->total(),
                'retention' => [
                    'automatic_purge' => true,
                    'days' => max(1, (int) config('operations.retention.audit_log_days', 730)),
                    'label' => 'Conservation : '.max(1, (int) config('operations.retention.audit_log_days', 730)).' jours — purge automatique mensuelle.',
                ],
            ],
        ]);
    }

    public function show(AuditLog $auditLog): JsonResponse
    {
        abort_unless(str_starts_with($auditLog->action, 'order.') || str_starts_with($auditLog->action, 'user.'), 404);
        $auditLog->load('actor:id,public_id,name,role');
        $this->attachAuditTargets(collect([$auditLog]));

        return ApiResponse::success(new AuditLogResource($auditLog));
    }

    /** @param Collection<int, AuditLog> $logs */
    private function attachAuditTargets(Collection $logs): void
    {
        $numericOrderIds = $logs
            ->filter(fn (AuditLog $log): bool => str_starts_with($log->action, 'order.') && ctype_digit((string) $log->auditable_id))
            ->map(fn (AuditLog $log): int => (int) $log->auditable_id)
            ->unique()
            ->values();
        $orderReferences = $numericOrderIds->isEmpty()
            ? collect()
            : Order::query()->whereIn('id', $numericOrderIds)->pluck('public_reference', 'id');
        $numericUserIds = $logs
            ->filter(fn (AuditLog $log): bool => str_starts_with($log->action, 'user.') && ctype_digit((string) $log->auditable_id))
            ->map(fn (AuditLog $log): int => (int) $log->auditable_id)
            ->unique()
            ->values();
        $userReferences = $numericUserIds->isEmpty()
            ? collect()
            : User::query()->whereIn('id', $numericUserIds)->pluck('public_id', 'id');

        $logs->each(function (AuditLog $log) use ($orderReferences, $userReferences): void {
            if (str_starts_with($log->action, 'order.')) {
                $snapshot = $log->getAttribute('before');
                $snapshotReference = is_array($snapshot) ? ($snapshot['public_reference'] ?? null) : null;
                $reference = is_string($snapshotReference) && $snapshotReference !== ''
                    ? $snapshotReference
                    : ($orderReferences->get((int) $log->auditable_id) ?? $log->auditable_id);
                $log->setAttribute('order_reference', $reference);
                $log->setAttribute('target_type', 'order');
                $log->setAttribute('target_reference', $reference);

                return;
            }

            if (str_starts_with($log->action, 'user.')) {
                $snapshot = $log->getAttribute('before');
                $snapshotReference = is_array($snapshot) ? ($snapshot['public_id'] ?? null) : null;
                $reference = is_string($snapshotReference) && $snapshotReference !== ''
                    ? $snapshotReference
                    : ($userReferences->get((int) $log->auditable_id) ?? $log->auditable_id);
                $log->setAttribute('target_type', 'user');
                $log->setAttribute('target_reference', $reference);
            }
        });
    }
}
