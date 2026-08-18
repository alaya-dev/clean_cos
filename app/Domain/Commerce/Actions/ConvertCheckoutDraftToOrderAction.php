<?php

namespace App\Domain\Commerce\Actions;

use App\Domain\Checkout\Actions\ResolveCheckoutSubmissionAction;
use App\Domain\Commerce\Models\CheckoutDraft;
use App\Domain\Commerce\Models\CheckoutField;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Services\CheckoutDraftService;
use App\Domain\Commerce\Services\OrderExchangeDetails;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ConvertCheckoutDraftToOrderAction
{
    public function __construct(private readonly CreateManualOrderAction $manualOrders, private readonly CheckoutDraftService $drafts, private readonly ResolveCheckoutSubmissionAction $checkout, private readonly OrderExchangeDetails $exchangeDetails) {}

    /** @param array<string, mixed> $data */
    public function handle(CheckoutDraft $draft, array $data, User $actor): Order
    {
        return DB::transaction(function () use ($draft, $data, $actor): Order {
            $locked = $this->drafts->findForUpdate($draft->public_token);
            abort_unless($locked !== null, 404);
            if ($locked->order_id !== null) {
                return Order::query()->with('items', 'checkoutValues')->findOrFail($locked->order_id);
            }

            $customer = array_merge((array) $locked->checkout_data, (array) $locked->customer_data, (array) ($data['customer'] ?? []));
            $snapshot = $locked->cart_snapshot;
            $items = $data['items'] ?? collect($snapshot)->map(fn (array $item): array => ['product_public_id' => $item['product_public_id'], 'variant_public_id' => $item['variant_public_id'] ?? null, 'quantity' => $item['quantity']])->all();
            $fields = CheckoutField::query()->where('is_active', true)->orderBy('sort_order')->get()->map(fn ($field): array => $field->only(['key', 'label', 'type', 'is_required', 'options', 'sort_order']))->all();
            $exchange = $data['exchange'] ?? $this->exchangeDetails->fromCheckoutCustomer($customer);
            if (! array_key_exists('article_designation', $exchange)) {
                $exchange = [
                    'is_exchange' => $exchange['is_exchange'] ?? false,
                    'article_designation' => $exchange['exchange_article_designation'] ?? null,
                    'article_count' => $exchange['exchange_article_count'] ?? null,
                ];
            }
            $payload = [
                'idempotency_key' => $data['idempotency_key'] ?? (string) Str::uuid(),
                'checkout_schema_version' => $this->checkout->schemaVersion($fields),
                'customer' => $customer,
                'items' => $items,
                'status' => $data['status'] ?? 'nouvelle',
                'exchange' => $exchange,
                'meta_attribution' => $locked->attribution_snapshot,
            ];
            $order = $this->manualOrders->handle($payload, $actor);
            DB::table('order_status_history')->insert([
                'order_id' => $order->id,
                'from_status' => null,
                'to_status' => 'brouillon',
                'reason' => 'Panier abandonné récupéré par l’administrateur.',
                'changed_by' => $actor->id,
                'created_at' => $order->created_at?->copy()->subSecond() ?? now()->subSecond(),
            ]);
            $this->drafts->markConverted($locked, $order->id);

            return $order;
        }, 3);
    }
}
