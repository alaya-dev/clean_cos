<?php

namespace App\Http\Resources\Api\Admin;

use Illuminate\Http\Resources\Json\JsonResource;

class AuditLogResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray($request): array
    {
        $before = $this->clean($this->resource->before);
        $after = $this->clean($this->resource->after);

        return [
            ...$this->resource->only([
                'public_id',
                'actor_user_id',
                'actor_role_snapshot',
                'action',
                'auditable_type',
                'auditable_id',
                'order_reference',
                'request_id',
                'created_at',
            ]),
            'target_type' => $this->resource->getAttribute('target_type'),
            'target_reference' => $this->resource->getAttribute('target_reference'),
            'actor' => $this->resource->relationLoaded('actor') && $this->resource->actor
                ? $this->resource->actor->only(['public_id', 'name', 'role'])
                : null,
            'changes' => $this->changes($before, $after),
            'before' => $before,
            'after' => $after,
        ];
    }

    private function clean(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $result = [];
        foreach ($value as $key => $item) {
            if ($this->hiddenKey((string) $key)) {
                continue;
            }

            $result[$key] = $this->clean($item);
        }

        return $result;
    }

    /** @return list<array{field: string, from: mixed, to: mixed}> */
    private function changes(mixed $before, mixed $after): array
    {
        if (! is_array($before) || ! is_array($after)) {
            return [];
        }

        $changes = [];
        $consumed = [];

        // Status transitions historically stored both values in the after
        // snapshot. Expose them as one readable before/after change.
        if (array_key_exists('from_status', $after) || array_key_exists('to_status', $after)) {
            $from = $after['from_status'] ?? $before['status'] ?? null;
            $to = $after['to_status'] ?? $after['status'] ?? null;
            if ($from !== $to) {
                $changes[] = ['field' => 'status', 'from' => $from, 'to' => $to];
            }
            $consumed = ['from_status', 'to_status', 'status'];
        }

        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $key) {
            if (in_array($key, $consumed, true)) {
                continue;
            }

            $from = array_key_exists($key, $before) ? $before[$key] : null;
            $to = array_key_exists($key, $after) ? $after[$key] : null;
            if ($from === $to) {
                continue;
            }

            $changes[] = ['field' => (string) $key, 'from' => $from, 'to' => $to];
        }

        return $changes;
    }

    private function hiddenKey(string $key): bool
    {
        $normalized = strtolower(str_replace(['-', ' '], '_', $key));
        $exact = [
            'password',
            'password_confirmation',
            'token',
            'session',
            'csrf',
            'csrf_token',
            'attachment',
            'body',
            'note',
            'notes',
            'name',
            'full_name',
            'customer_name',
            'subject',
            'description',
            'raw_attribution',
            '_fbp',
            '_fbc',
            'phone_hash',
        ];

        return in_array($normalized, $exact, true)
            || str_contains($normalized, 'credential')
            || str_contains($normalized, 'access_token')
            || str_contains($normalized, 'refresh_token')
            || str_contains($normalized, 'password')
            || str_contains($normalized, 'phone')
            || str_contains($normalized, 'telephone')
            || str_contains($normalized, 'address')
            || str_contains($normalized, 'email');
    }
}
