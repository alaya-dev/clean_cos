<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Audit\Actions\RecordAuditEventAction;
use App\Domain\Catalog\Actions\AdjustInventoryAction;
use App\Domain\Catalog\Actions\BulkAdjustInventoryAction;
use App\Domain\Catalog\Models\InventoryMovement;
use App\Domain\Catalog\Models\Product;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['product_public_id' => ['nullable', 'ulid'], 'search' => ['nullable', 'string', 'max:100'], 'type' => ['nullable', 'string', 'max:60'], 'date_from' => ['nullable', 'date'], 'date_to' => ['nullable', 'date'], 'per_page' => ['nullable', 'integer', 'between:1,100']]);
        $query = InventoryMovement::query()->with(['product', 'variant'])->latest();
        if ($data['product_public_id'] ?? null) {
            $query->whereHas('product', fn ($products) => $products->where('public_id', $data['product_public_id']));
        }
        if ($data['search'] ?? null) {
            $query->whereHas('product', fn ($products) => $products->where('name', 'like', '%'.$data['search'].'%'));
        }
        if ($data['type'] ?? null) {
            $query->where('type', $data['type']);
        }
        if ($data['date_from'] ?? null) {
            $query->whereDate('created_at', '>=', $data['date_from']);
        }
        if ($data['date_to'] ?? null) {
            $query->whereDate('created_at', '<=', $data['date_to']);
        }

        return response()->json(['data' => $query->paginate($data['per_page'] ?? 25)]);
    }

    public function adjust(Request $request, Product $product, AdjustInventoryAction $action, RecordAuditEventAction $audit): JsonResponse
    {
        $data = $request->validate(['variant_public_id' => ['nullable', 'ulid'], 'quantity_delta' => ['required', 'integer', 'not_in:0', 'between:-100000,100000'], 'reason' => ['required', 'string', 'between:3,500']]);
        $actor = $request->user();
        if ($actor === null) {
            abort(401);
        }
        $action->handle($product, $data['variant_public_id'] ?? null, $data['quantity_delta'], $data['reason'], $actor->id);
        $audit->handle('inventory.adjusted', $product, $actor, after: ['quantity_delta' => $data['quantity_delta'], 'variant_public_id' => $data['variant_public_id'] ?? null]);

        return response()->json(['data' => null]);
    }

    public function bulkAdjust(Request $request, BulkAdjustInventoryAction $action): JsonResponse
    {
        $data = $request->validate([
            'operation' => ['required', 'in:set,increase,decrease'],
            'quantity' => ['required', 'integer', 'min:0', 'max:100000'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_public_id' => ['required', 'ulid', 'distinct'],
            'items.*.variant_public_id' => ['nullable', 'ulid'],
        ]);
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $updated = $action->handle($data['items'], $data['operation'], $data['quantity'], $actor);

        return response()->json(['data' => $updated]);
    }
}
