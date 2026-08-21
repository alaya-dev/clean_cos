<?php

namespace App\Domain\FirstDelivery\Services;

use App\Domain\Commerce\Models\Order;
use App\Domain\FirstDelivery\Models\FirstDeliveryLocality;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FirstDeliveryLocalityService
{
    /** @param array<array-key, mixed> $providerLocalities */
    public function synchronize(array $providerLocalities): int
    {
        if (count($providerLocalities) > 10_000) {
            throw ValidationException::withMessages(['localities' => 'La réponse First Delivery contient trop de localités.']);
        }

        $timestamp = now();
        $rows = collect($providerLocalities)
            ->filter(fn (mixed $item): bool => is_array($item))
            ->map(function (array $item) use ($timestamp): ?array {
                $id = filter_var($item['locality_id'] ?? null, FILTER_VALIDATE_INT);
                $locality = $this->text($item['locality_name'] ?? null, 180);
                $delegation = $this->text($item['delegation_name'] ?? null, 180);
                $governorate = $this->text($item['governorate_name'] ?? null, 120);
                if ($id === false || $id < 1 || $locality === null || $delegation === null || $governorate === null) {
                    return null;
                }

                return [
                    'locality_id' => $id,
                    'locality_name' => $locality,
                    'delegation_name' => $delegation,
                    'governorate_name' => $governorate,
                    'last_synced_at' => $timestamp,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];
            })
            ->filter()
            ->unique('locality_id')
            ->values();

        if ($rows->isEmpty()) {
            throw ValidationException::withMessages(['localities' => 'First Delivery n’a retourné aucune localité exploitable.']);
        }

        DB::transaction(function () use ($rows): void {
            $rows->chunk(500)->each(function (Collection $chunk): void {
                DB::table('first_delivery_localities')->upsert(
                    $chunk->all(),
                    ['locality_id'],
                    ['locality_name', 'delegation_name', 'governorate_name', 'last_synced_at', 'updated_at'],
                );
            });
        });

        return $rows->count();
    }

    public function resolveForOrder(Order $order): ?FirstDeliveryLocality
    {
        if ($order->first_delivery_locality_id !== null) {
            return FirstDeliveryLocality::query()->find($order->first_delivery_locality_id);
        }

        $city = $this->normalize((string) $order->customer_city);
        $governorate = $this->normalize((string) $order->customer_governorate);
        if ($city === '' || $governorate === '') {
            return null;
        }

        $matches = FirstDeliveryLocality::query()
            ->get()
            ->filter(fn (FirstDeliveryLocality $locality): bool => $this->normalize($locality->governorate_name) === $governorate)
            ->filter(fn (FirstDeliveryLocality $locality): bool => in_array($city, [
                $this->normalize($locality->locality_name),
                $this->normalize($locality->delegation_name),
            ], true))
            ->values();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    /** @return Collection<int, FirstDeliveryLocality> */
    public function options(?string $governorate, ?string $search = null): Collection
    {
        $query = FirstDeliveryLocality::query()->orderBy('governorate_name')->orderBy('delegation_name')->orderBy('locality_name');
        if (filled($governorate)) {
            $query->where('governorate_name', trim((string) $governorate));
        }
        if (filled($search)) {
            $value = '%'.trim((string) $search).'%';
            $query->where(fn ($query) => $query
                ->where('locality_name', 'like', $value)
                ->orWhere('delegation_name', 'like', $value));
        }

        return $query->limit(200)->get();
    }

    private function normalize(string $value): string
    {
        return mb_strtolower(trim(Str::ascii($value)));
    }

    private function text(mixed $value, int $limit): ?string
    {
        return is_scalar($value) && trim((string) $value) !== ''
            ? mb_substr(trim((string) $value), 0, $limit)
            : null;
    }
}
