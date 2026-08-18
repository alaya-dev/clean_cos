<?php

namespace App\Domain\Commerce\Services;

use App\Domain\Commerce\Models\Order;

class OrderDesignationFormatter
{
    public function format(Order $order): string
    {
        $items = $order->relationLoaded('items') ? $order->items : $order->items()->get();
        $parts = $items->map(function ($item): ?string {
            $name = trim((string) $item->product_name_snapshot);
            if ($name === '') {
                return null;
            }

            $name = str_replace('"', "'", $name);
            $variantSnapshot = $item->getAttribute('variant_snapshot');
            $variantValues = collect(is_array($variantSnapshot) ? $variantSnapshot : [])
                ->pluck('value')
                ->filter(static fn (mixed $value): bool => is_string($value) && trim($value) !== '')
                ->map(static fn (string $value): string => str_replace('"', "'", trim($value)))
                ->values()
                ->implode(' / ');
            $variant = $variantValues !== '' ? ' ("'.$variantValues.'")' : '';

            return (string) ((int) $item->quantity).' "'.$name.'"'.$variant;
        })->filter()->values()->implode(' // ');

        return $parts !== '' ? $parts : 'PC-'.$order->public_reference;
    }
}
