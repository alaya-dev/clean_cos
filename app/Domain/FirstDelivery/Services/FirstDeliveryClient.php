<?php

namespace App\Domain\FirstDelivery\Services;

use App\Domain\FirstDelivery\Models\FirstDeliveryConfiguration;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class FirstDeliveryClient
{
    public function __construct(private readonly FirstDeliveryConfigurationService $configurations) {}

    public function getLocalities(FirstDeliveryConfiguration $configuration): FirstDeliveryResult
    {
        return $this->request($configuration, 'GET', 'localities');
    }

    /** @param array<string, mixed> $payload */
    public function createOrder(FirstDeliveryConfiguration $configuration, array $payload): FirstDeliveryResult
    {
        return $this->request($configuration, 'POST', 'create', $payload, true);
    }

    public function getOrderStatus(FirstDeliveryConfiguration $configuration, string $barcode): FirstDeliveryResult
    {
        return $this->request($configuration, 'POST', 'etat', ['barCode' => $barcode]);
    }

    public function cancelOrder(FirstDeliveryConfiguration $configuration, string $barcode): FirstDeliveryResult
    {
        return $this->request($configuration, 'POST', 'cancel-orders', ['barCodes' => [$barcode]]);
    }

    /** @param array<int, string> $barcodes */
    public function createPickup(FirstDeliveryConfiguration $configuration, array $barcodes): FirstDeliveryResult
    {
        return $this->request($configuration, 'POST', 'pickup', ['barCodes' => array_values($barcodes)], pickup: true);
    }

    public function printPickup(FirstDeliveryConfiguration $configuration, string $pickupId): FirstDeliveryResult
    {
        $pickupId = $this->pickupId($pickupId);
        if ($pickupId === null) {
            return new FirstDeliveryResult(false, false, false, false, null, 'pickup_id_invalid', 'Le numéro de manifeste First Delivery est invalide.', null, 0);
        }

        return $this->request($configuration, 'POST', 'request-print/'.rawurlencode($pickupId), pickup: true, withoutBody: true);
    }

    /** @param array<string, mixed>|null $payload */
    private function request(
        FirstDeliveryConfiguration $configuration,
        string $method,
        string $path,
        ?array $payload = null,
        bool $creation = false,
        bool $pickup = false,
        bool $withoutBody = false,
    ): FirstDeliveryResult {
        $token = $this->configurations->decryptToken($configuration);
        $url = $this->endpoint($configuration->api_base_url, $path);
        if ($token === null || $url === null) {
            return new FirstDeliveryResult(
                false,
                false,
                false,
                false,
                null,
                'configuration_invalid',
                'La configuration First Delivery enregistrée est incomplète ou indisponible.',
                null,
                0,
            );
        }

        $startedAt = hrtime(true);
        try {
            $request = $this->pendingRequest($token);
            $response = match (true) {
                $method === 'GET' => $request->get($url),
                $withoutBody => $request->send($method, $url),
                default => $request->post($url, $payload ?? []),
            };
            $duration = (int) round((hrtime(true) - $startedAt) / 1_000_000);
            $json = $response->json();
            $body = is_array($json) ? $json : null;
            $providerAccepted = $response->successful()
                && $body !== null
                && array_key_exists('isError', $body)
                && $body['isError'] === false;

            if ($providerAccepted) {
                $barcode = $creation ? $this->barcode(data_get($body, 'result.barCode')) : null;
                $pickupId = $pickup ? $this->pickupId(data_get($body, 'result.pickup')) : null;
                $printUrl = ($creation || $pickup) ? $this->safeProviderUrl(data_get($body, 'result.link')) : null;
                $classification = $creation && $barcode === null
                    ? 'accepted_without_barcode'
                    : ($pickup && $pickupId === null ? 'accepted_without_pickup_id' : 'accepted');

                return new FirstDeliveryResult(
                    true,
                    false,
                    false,
                    true,
                    $response->status(),
                    $classification,
                    $this->message($body),
                    $body,
                    $duration,
                    $barcode,
                    $printUrl,
                    $pickupId,
                );
            }

            $temporary = $response->status() === 429 || $response->serverError();

            return new FirstDeliveryResult(
                false,
                $temporary,
                false,
                true,
                $response->status(),
                $temporary ? 'temporary_failure' : 'provider_rejected',
                $this->message($body) ?? 'First Delivery a refusé la demande.',
                $body,
                $duration,
            );
        } catch (ConnectionException) {
            $duration = (int) round((hrtime(true) - $startedAt) / 1_000_000);

            return new FirstDeliveryResult(
                false,
                false,
                true,
                true,
                null,
                'network_uncertain',
                'Le résultat First Delivery est incertain. Vérifiez le tableau de bord du transporteur avant toute nouvelle tentative.',
                null,
                $duration,
            );
        }
    }

    private function pendingRequest(string $token): PendingRequest
    {
        return Http::withToken($token)
            ->acceptJson()
            ->asJson()
            ->connectTimeout((int) config('first_delivery.connect_timeout_seconds'))
            ->timeout((int) config('first_delivery.timeout_seconds'))
            ->withOptions(['allow_redirects' => false, 'verify' => true]);
    }

    private function endpoint(string $baseUrl, string $path): ?string
    {
        $parts = parse_url($baseUrl);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $allowed = array_map('strtolower', (array) config('first_delivery.allowed_hosts'));
        if (($parts['scheme'] ?? null) !== 'https' || $host === '' || ! in_array($host, $allowed, true)) {
            return null;
        }

        return rtrim($baseUrl, '/').'/'.ltrim($path, '/');
    }

    private function barcode(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);

        return preg_match('/^\d{12}$/D', $value) === 1 ? $value : null;
    }

    private function pickupId(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);

        return preg_match('/^[A-Za-z0-9_-]{1,120}$/D', $value) === 1 ? $value : null;
    }

    private function safeProviderUrl(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        $parts = parse_url(trim($value));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $allowed = array_map('strtolower', (array) config('first_delivery.allowed_hosts'));

        return ($parts['scheme'] ?? null) === 'https' && in_array($host, $allowed, true)
            ? trim($value)
            : null;
    }

    /** @param array<string, mixed>|array<int, mixed>|null $payload */
    private function message(?array $payload): ?string
    {
        $message = data_get($payload, 'message');

        return is_scalar($message) && trim((string) $message) !== ''
            ? mb_substr(trim((string) $message), 0, 500)
            : null;
    }
}
