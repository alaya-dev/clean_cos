<?php

namespace App\Domain\Navex\Services;

use App\Domain\Navex\Models\NavexConfiguration;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class NavexClient
{
    public function __construct(private readonly NavexConfigurationService $configurations) {}

    /** @param array<string, string|int> $fields */
    public function create(NavexConfiguration $configuration, array $fields): NavexResult
    {
        return $this->post($configuration, 'creation_credential_encrypted', $fields, true);
    }

    public function track(NavexConfiguration $configuration, string $code): NavexResult
    {
        return $this->post($configuration, 'tracking_credential_encrypted', [
            'code' => $code,
            'include_date' => '1',
            'include_prix' => '1',
            'include_echange' => '1',
        ], false);
    }

    /** @param array<int, string> $codes */
    public function trackMany(NavexConfiguration $configuration, array $codes): NavexResult
    {
        return $this->post($configuration, 'tracking_credential_encrypted', ['codes' => implode(', ', $codes)], false);
    }

    public function pending(NavexConfiguration $configuration): NavexResult
    {
        return $this->post($configuration, 'tracking_credential_encrypted', ['getattente' => '1'], false);
    }

    public function delete(NavexConfiguration $configuration, string $trackingCode): NavexResult
    {
        return $this->post($configuration, 'deletion_credential_encrypted', ['delete_code' => $trackingCode], false);
    }

    /** @param array<string, string|int> $fields */
    private function post(NavexConfiguration $configuration, string $credentialField, array $fields, bool $creation): NavexResult
    {
        $credential = $this->configurations->decrypt($configuration, $credentialField);
        if ($credential === null) {
            return new NavexResult(false, false, false, false, null, 'configuration_invalid', 'La configuration Navex enregistrée est incomplète ou indisponible.', null, null, 0);
        }

        $url = $this->endpoint($configuration->api_base_url, $credential);
        if ($url === null) {
            return new NavexResult(false, false, false, false, null, 'configuration_invalid', 'L’adresse Navex configurée n’est pas autorisée.', null, null, 0);
        }

        $startedAt = hrtime(true);
        try {
            $response = Http::asForm()
                ->acceptJson()
                ->connectTimeout((int) config('navex.connect_timeout_seconds'))
                ->timeout((int) config('navex.timeout_seconds'))
                ->withOptions(['allow_redirects' => false, 'verify' => true])
                ->post($url, $fields);
            $duration = (int) round((hrtime(true) - $startedAt) / 1_000_000);
            $json = $response->json();
            $payload = is_array($json) ? $json : null;
            if ($response->successful()) {
                $trackingCode = $this->trackingCode($payload, $creation);
                if ($creation && $trackingCode === null) {
                    // Navex documents a successful creation response containing
                    // only “Product Added.”. The parcel exists even though this
                    // endpoint has not returned its barcode yet.
                    return new NavexResult(true, false, false, true, $response->status(), 'accepted_without_tracking_code', $this->message($payload), null, $payload, $duration);
                }

                return new NavexResult(true, false, false, true, $response->status(), 'accepted', $this->message($payload), $trackingCode, $payload, $duration);
            }

            $temporary = $response->status() === 429 || $response->serverError();

            return new NavexResult(false, $temporary, false, true, $response->status(), $temporary ? 'temporary_failure' : 'provider_rejected', $this->message($payload) ?? 'Navex a refusé la demande.', null, $payload, $duration);
        } catch (ConnectionException) {
            $duration = (int) round((hrtime(true) - $startedAt) / 1_000_000);

            return new NavexResult(false, false, true, true, null, 'network_uncertain', 'Le résultat Navex est incertain. Vérification nécessaire avant toute nouvelle tentative.', null, null, $duration);
        }
    }

    private function endpoint(string $baseUrl, string $credential): ?string
    {
        $parts = parse_url($baseUrl);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $allowed = array_map('strtolower', (array) config('navex.allowed_hosts'));
        if (($parts['scheme'] ?? null) !== 'https' || $host === '' || ! in_array($host, $allowed, true)) {
            return null;
        }

        return rtrim($baseUrl, '/').'/api/'.rawurlencode($credential).'/v1/post.php';
    }

    /** @param array<string, mixed>|array<int, mixed>|null $payload */
    private function trackingCode(?array $payload, bool $creationResponse = false): ?string
    {
        if ($payload === null) {
            return null;
        }
        foreach (['code', 'code_barre', 'tracking_code'] as $key) {
            $candidate = data_get($payload, $key);
            if (is_scalar($candidate) && trim((string) $candidate) !== '') {
                return trim((string) $candidate);
            }
        }

        // Navex sometimes returns the barcode in status_message immediately
        // after a successful creation. Only accept the known barcode shape so
        // a textual acknowledgement such as "Product Added." can never be
        // persisted as a tracking code.
        if ($creationResponse) {
            $candidate = data_get($payload, 'status_message');
            if (is_scalar($candidate)) {
                $candidate = trim((string) $candidate);
                if (preg_match('/^\d{12}$/D', $candidate) === 1) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /** @param array<string, mixed>|array<int, mixed>|null $payload */
    private function message(?array $payload): ?string
    {
        $value = data_get($payload, 'status_message') ?? data_get($payload, 'message');

        return is_scalar($value) ? mb_substr(trim((string) $value), 0, 500) : null;
    }
}
