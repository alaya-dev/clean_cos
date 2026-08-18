<?php

namespace App\Domain\Settings\Services;

use App\Domain\Settings\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class StoreSettings
{
    private const CACHE_PREFIX = 'pc:cache:settings:v2:';

    private ?bool $settingsTableExists = null;

    public function get(string $key): mixed
    {
        $definition = $this->definition($key);

        if (! $this->settingsTableExists()) {
            return $this->defaultValue($key, $definition['default']);
        }

        $cache = Cache::store();
        $cacheKey = self::CACHE_PREFIX.$key;
        $cached = $cache->get($cacheKey);
        if (is_array($cached) && array_key_exists('value', $cached)) {
            return $this->normalizeValue($definition, $cached['value']);
        }

        // Supports values cached by the previous implementation during a rolling deploy.
        if ($cache->has($cacheKey)) {
            return $this->normalizeValue($definition, $cached);
        }

        $value = Setting::query()->where('key', $key)->value('value');
        $normalized = $value === null
            ? $this->defaultValue($key, $definition['default'])
            : $this->normalizeValue($definition, $value);
        $cache->forever($cacheKey, ['value' => $normalized]);

        return $normalized;
    }

    /** @param array<string, mixed> $values */
    public function update(array $values, int $actorId): void
    {
        foreach ($values as $key => $value) {
            $this->validateValue($key, $value);
        }

        DB::transaction(function () use ($values, $actorId): void {
            foreach ($values as $key => $value) {
                Setting::query()->updateOrCreate(['key' => $key], ['value' => $value, 'updated_by' => $actorId]);
                DB::afterCommit(fn () => Cache::forget(self::CACHE_PREFIX.$key));
            }
            DB::afterCommit(fn () => Cache::forget('pc:cache:storefront:home'));
            DB::afterCommit(fn () => Cache::forget('pc:cache:storefront:layout'));
        });
        foreach (array_keys($values) as $key) {
            Cache::forget(self::CACHE_PREFIX.$key);
        }
        Cache::forget('pc:cache:storefront:home');
        Cache::forget('pc:cache:storefront:layout');
    }

    public function incrementSchemaVersion(int $actorId): int
    {
        $next = DB::transaction(function () use ($actorId): int {
            $setting = Setting::query()->where('key', 'checkout.schema_version')->lockForUpdate()->firstOrFail();
            $next = (int) $setting->value + 1;
            $setting->update(['value' => $next, 'updated_by' => $actorId]);
            DB::afterCommit(fn () => Cache::forget(self::CACHE_PREFIX.'checkout.schema_version'));
            DB::afterCommit(fn () => Cache::forget('pc:cache:storefront:home'));

            return $next;
        });
        Cache::forget(self::CACHE_PREFIX.'checkout.schema_version');
        Cache::forget('pc:cache:storefront:home');

        return $next;
    }

    /** @return array<string, mixed> */
    private function definition(string $key): array
    {
        $definitions = config('store.settings', []);
        $definition = is_array($definitions) ? ($definitions[$key] ?? null) : null;
        if (! is_array($definition)) {
            throw ValidationException::withMessages(['settings' => 'Un paramètre transmis n’est pas autorisé.']);
        }

        return $definition;
    }

    private function validateValue(string $key, mixed $value): void
    {
        $definition = $this->definition($key);
        $valid = match ($definition['type']) {
            'integer' => is_int($value) && $value >= ($definition['min'] ?? PHP_INT_MIN),
            'nullable_integer' => $value === null || (is_int($value) && $value >= ($definition['min'] ?? PHP_INT_MIN)),
            'boolean' => is_bool($value),
            'array' => is_array($value),
            'nullable_string' => $value === null || (is_string($value) && mb_strlen($value) <= ($definition['max'] ?? 1000)),
            default => false,
        };
        if (! $valid) {
            throw ValidationException::withMessages([$key => 'La valeur du paramètre est invalide.']);
        }
    }

    private function defaultValue(string $key, mixed $default): mixed
    {
        return match ($key) {
            'shipping.fixed_fee_millimes' => (int) config('commerce.shipping_fixed_fee_millimes', $default),
            'shipping.free_threshold_millimes' => config('commerce.shipping_free_threshold_millimes', $default),
            'shipping.free_threshold_enabled' => config('commerce.shipping_free_threshold_millimes') !== null,
            default => $default,
        };
    }

    private function settingsTableExists(): bool
    {
        return $this->settingsTableExists ??= Schema::hasTable('settings');
    }

    /** @param array<string, mixed> $definition */
    private function normalizeValue(array $definition, mixed $value): mixed
    {
        return match ($definition['type']) {
            'integer' => (int) $value,
            'nullable_integer' => $value === null ? null : (int) $value,
            'boolean' => is_bool($value) ? $value : in_array($value, [1, '1', 'true'], true),
            'array' => is_array($value) ? $value : [],
            'nullable_string' => $value === null ? null : (string) $value,
            default => $value,
        };
    }
}
