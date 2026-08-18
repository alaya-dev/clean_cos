<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Catalog\Models\Product;
use App\Domain\Commerce\Actions\ConvertCheckoutDraftToOrderAction;
use App\Domain\Commerce\Models\CheckoutDraft;
use App\Domain\Commerce\Support\OrderStatusFlow;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CheckoutDraftController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['per_page' => ['nullable', 'integer', 'between:1,100']]);
        $drafts = CheckoutDraft::query()->abandoned()->orderByDesc('last_activity_at')->paginate($data['per_page'] ?? 25);
        $snapshots = $this->cartSnapshotsWithImages($drafts->getCollection()->all());
        $drafts->getCollection()->transform(fn (CheckoutDraft $draft): array => $this->summary($draft, $snapshots[$draft->id] ?? []));

        return response()->json(['data' => $drafts]);
    }

    public function show(string $token): JsonResponse
    {
        $draft = CheckoutDraft::query()->where('public_token', $token)->whereNull('converted_at')->firstOrFail();
        $cartSnapshot = $this->cartSnapshotsWithImages([$draft])[$draft->id] ?? [];

        return response()->json(['data' => [
            'public_token' => $draft->public_token,
            'customer_data' => $draft->customer_data,
            'cart_snapshot' => $cartSnapshot,
            'checkout_data' => $draft->checkout_data,
            'last_activity_at' => $draft->last_activity_at ? now()->parse((string) $draft->last_activity_at)->toIso8601String() : null,
        ]]);
    }

    public function destroy(string $token): JsonResponse
    {
        $draft = CheckoutDraft::query()->where('public_token', $token)->whereNull('converted_at')->firstOrFail();
        $draft->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    public function convert(Request $request, string $token, ConvertCheckoutDraftToOrderAction $converter): JsonResponse
    {
        $data = $request->validate([
            'idempotency_key' => ['nullable', 'uuid'],
            'status' => ['required', Rule::in(array_diff(OrderStatusFlow::STATUSES, ['annulee']))],
            'customer' => ['nullable', 'array'],
            'customer.*' => ['nullable'],
            'items' => ['nullable', 'array', 'min:1', 'max:100'],
            'items.*.product_public_id' => ['required', 'ulid'],
            'items.*.variant_public_id' => ['nullable', 'ulid'],
            'items.*.quantity' => ['required', 'integer', 'between:1,99'],
            'exchange' => ['sometimes', 'array'],
            'exchange.is_exchange' => ['sometimes'],
            'exchange.article_designation' => ['nullable', 'string', 'max:500'],
            'exchange.article_count' => ['nullable', 'integer', 'min:1'],
        ]);
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $draft = CheckoutDraft::query()->where('public_token', $token)->firstOrFail();
        $order = $converter->handle($draft, $data, $actor);

        return response()->json(['data' => ['order' => $order->load('items'), 'converted' => true]], 201);
    }

    /**
     * @param  array<int, array<string, mixed>>  $cart
     * @return array<string, mixed>
     */
    private function summary(CheckoutDraft $draft, array $cart): array
    {
        $items = collect($cart);
        $estimated = $items->sum(fn (array $item): int => (int) ($item['effective_price_millimes'] ?? 0) * (int) ($item['quantity'] ?? 0));

        return ['record_type' => 'checkout_draft', 'token' => $draft->public_token, 'customer_data' => $draft->customer_data, 'items' => $items->map(fn (array $item): array => ['name' => $item['name'] ?? 'Produit', 'variant_label' => $item['variant_label'] ?? null, 'quantity' => $item['quantity'] ?? 1, 'image_url' => $item['image_url'] ?? null])->all(), 'estimated_total_millimes' => $estimated, 'last_activity_at' => $draft->last_activity_at ? now()->parse((string) $draft->last_activity_at)->toIso8601String() : null];
    }

    /**
     * Resolve a compact, current thumbnail for legacy drafts that predate image snapshots.
     *
     * @param  array<int, CheckoutDraft>  $drafts
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function cartSnapshotsWithImages(array $drafts): array
    {
        $snapshots = collect($drafts)->mapWithKeys(fn (CheckoutDraft $draft): array => [$draft->id => array_values((array) $draft->cart_snapshot)]);
        $productPublicIds = $snapshots->flatMap(fn (array $items) => $items)
            ->pluck('product_public_id')
            ->filter(fn (mixed $publicId): bool => is_string($publicId) && $publicId !== '')
            ->unique()
            ->values();

        if ($productPublicIds->isEmpty()) {
            return $snapshots->all();
        }

        $products = Product::query()
            ->select(['id', 'public_id'])
            ->whereIn('public_id', $productPublicIds)
            ->with([
                'allVariants:id,product_id,public_id',
                'images' => fn ($query) => $query
                    ->select(['id', 'product_id', 'product_variant_id', 'path', 'renditions', 'processing_status', 'is_primary', 'sort_order'])
                    ->orderByDesc('is_primary')
                    ->orderBy('sort_order'),
            ])
            ->get()
            ->keyBy('public_id');

        return $snapshots->map(function (array $items) use ($products): array {
            return collect($items)->map(function (array $item) use ($products): array {
                if (is_string($item['image_url'] ?? null) && $item['image_url'] !== '') {
                    return $item;
                }

                /** @var Product|null $product */
                $product = $products->get($item['product_public_id'] ?? '');
                if (! $product) {
                    return $item;
                }

                $variant = $product->allVariants->firstWhere('public_id', $item['variant_public_id'] ?? null);
                $image = $variant === null
                    ? $product->images->first()
                    : ($product->images->firstWhere('product_variant_id', $variant->id) ?? $product->images->first());

                return [...$item, 'image_url' => $image?->public_url];
            })->all();
        })->all();
    }
}
