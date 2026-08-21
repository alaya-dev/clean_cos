<?php

namespace App\Domain\FirstDelivery\Services;

use App\Domain\FirstDelivery\Models\FirstDeliveryConfiguration;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

class FirstDeliveryConfigurationService
{
    public function current(): ?FirstDeliveryConfiguration
    {
        return FirstDeliveryConfiguration::query()->latest('updated_at')->first();
    }

    public function usable(): ?FirstDeliveryConfiguration
    {
        $configuration = $this->current();

        return $configuration !== null
            && $configuration->mode !== 'disabled'
            && $configuration->complete()
            ? $configuration
            : null;
    }

    public function decryptToken(FirstDeliveryConfiguration $configuration): ?string
    {
        if (! is_string($configuration->token_encrypted) || $configuration->token_encrypted === '') {
            return null;
        }

        try {
            return Crypt::decryptString($configuration->token_encrypted);
        } catch (DecryptException) {
            return null;
        }
    }

    public function encryptWhenProvided(?string $value, ?string $existing): ?string
    {
        $normalized = is_string($value) ? trim($value) : '';

        return $normalized === '' ? $existing : Crypt::encryptString($normalized);
    }

    /** @return array<string, bool|string|null> */
    public function safe(FirstDeliveryConfiguration $configuration): array
    {
        $configured = filled($configuration->token_encrypted);

        return [
            'public_id' => $configuration->public_id,
            'mode' => $configuration->mode,
            'api_base_url' => $configuration->api_base_url,
            'token_configured' => $configured,
            'token_masked' => $configured ? '••••••••••••••••••••' : null,
            'configuration_complete' => $configuration->complete(),
            'last_tested_at' => $configuration->last_tested_at?->toIso8601String(),
            'last_test_status' => $configuration->last_test_status,
            'last_test_message' => $configuration->last_test_message,
            'last_localities_synced_at' => $configuration->last_localities_synced_at?->toIso8601String(),
        ];
    }
}
