<?php

namespace App\Domain\Navex\Services;

use App\Domain\Navex\Models\NavexConfiguration;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

class NavexConfigurationService
{
    public function current(): ?NavexConfiguration
    {
        return NavexConfiguration::query()->latest('updated_at')->first();
    }

    public function usableForCreation(): ?NavexConfiguration
    {
        $configuration = $this->current();

        return $configuration !== null && $configuration->mode !== 'disabled' && $configuration->complete()
            ? $configuration
            : null;
    }

    public function decrypt(NavexConfiguration $configuration, string $field): ?string
    {
        $encrypted = $configuration->getAttribute($field);
        if (! is_string($encrypted) || $encrypted === '') {
            return null;
        }

        try {
            return Crypt::decryptString($encrypted);
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
    public function safe(NavexConfiguration $configuration): array
    {
        return [
            'public_id' => $configuration->public_id,
            'mode' => $configuration->mode,
            'api_base_url' => $configuration->api_base_url,
            'creation_credential_configured' => filled($configuration->creation_credential_encrypted),
            'tracking_credential_configured' => filled($configuration->tracking_credential_encrypted),
            'deletion_credential_configured' => filled($configuration->deletion_credential_encrypted),
            'sender_name' => $configuration->sender_name,
            'sender_location' => $configuration->sender_location,
            'sender_governorate' => $configuration->sender_governorate,
            // Keep the legacy field in the safe response for API compatibility, but expose its fixed value.
            'parcel_opening_option' => 'Oui',
            'configuration_complete' => $configuration->complete(),
            'last_tested_at' => $configuration->last_tested_at?->toIso8601String(),
            'last_test_status' => $configuration->last_test_status,
            'last_test_message' => $configuration->last_test_message,
        ];
    }
}
