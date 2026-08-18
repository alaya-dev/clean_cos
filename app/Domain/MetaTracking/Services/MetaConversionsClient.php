<?php

namespace App\Domain\MetaTracking\Services;

use App\Domain\MetaTracking\Models\MetaConfiguration;
use App\Domain\MetaTracking\Models\MetaEvent;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class MetaConversionsClient
{
    public function __construct(private readonly MetaGraphEndpoint $endpoint) {}

    public function send(MetaEvent $event): MetaDeliveryResult
    {
        $configuration = $event->configuration;
        if (! $configuration || ! $configuration->tracking_enabled || ! $this->validPixelId($configuration->pixel_id)) {
            return new MetaDeliveryResult(false, false, 'configuration_invalid', graphApiVersion: (string) config('meta.graph_api_version'), sourceUrl: $event->source_url);
        }
        if (! is_string($configuration->capi_access_token_encrypted) || $configuration->capi_access_token_encrypted === '') {
            return new MetaDeliveryResult(false, false, 'configuration_invalid', graphApiVersion: (string) config('meta.graph_api_version'), sourceUrl: $event->source_url);
        }

        try {
            $token = Crypt::decryptString($configuration->capi_access_token_encrypted);
        } catch (DecryptException) {
            return new MetaDeliveryResult(false, false, 'token_decryption_failed', graphApiVersion: (string) config('meta.graph_api_version'), sourceUrl: $event->source_url);
        }

        $pixelId = $configuration->pixel_id;
        if (! is_string($pixelId)) {
            return new MetaDeliveryResult(false, false, 'configuration_invalid', graphApiVersion: (string) config('meta.graph_api_version'), sourceUrl: $event->source_url);
        }

        return $this->post(
            $pixelId,
            $token,
            [$this->payload($event)],
            $configuration->test_mode ? $configuration->test_event_code : null,
        );
    }

    public function testConnection(MetaConfiguration $configuration): MetaDeliveryResult
    {
        try {
            $sourceUrl = $this->testSourceUrl();
        } catch (\LogicException) {
            return new MetaDeliveryResult(false, false, 'configuration_invalid', graphApiVersion: (string) config('meta.graph_api_version'));
        }
        if (! $configuration->tracking_enabled || ! $this->validPixelId($configuration->pixel_id) || blank($configuration->capi_access_token_encrypted)) {
            return new MetaDeliveryResult(false, false, 'configuration_invalid', graphApiVersion: (string) config('meta.graph_api_version'), sourceUrl: $sourceUrl);
        }

        try {
            $token = Crypt::decryptString((string) $configuration->capi_access_token_encrypted);
        } catch (DecryptException) {
            return new MetaDeliveryResult(false, false, 'token_decryption_failed', graphApiVersion: (string) config('meta.graph_api_version'), sourceUrl: $sourceUrl);
        }

        $eventId = 'pc_test_'.strtolower((string) Str::ulid());
        $payload = [
            'event_name' => 'PageView',
            'event_time' => now()->getTimestamp(),
            'event_id' => $eventId,
            'action_source' => 'website',
            'event_source_url' => $sourceUrl,
            'user_data' => ['external_id' => hash('sha256', 'ToutDispo-synthetic-connection-test')],
        ];

        return $this->post(
            (string) $configuration->pixel_id,
            $token,
            [$payload],
            $configuration->test_mode ? $configuration->test_event_code : null,
        );
    }

    /** @param array<int, array<string, mixed>> $events */
    private function post(string $pixelId, string $token, array $events, ?string $testEventCode): MetaDeliveryResult
    {
        try {
            $response = Http::connectTimeout(3)
                ->timeout(8)
                ->withoutRedirecting()
                ->acceptJson()
                ->asJson()
                ->post($this->endpoint->events($pixelId), [
                    'access_token' => $token,
                    'data' => $events,
                    ...($testEventCode ? ['test_event_code' => $testEventCode] : []),
                ]);
        } catch (ConnectionException $exception) {
            $classification = str_contains(strtolower($exception->getMessage()), 'timed out') ? 'timeout' : 'network_error';

            return new MetaDeliveryResult(false, true, $classification, requestSent: false, graphApiVersion: (string) config('meta.graph_api_version'), sourceUrl: $this->eventSourceUrl($events));
        }

        $json = $response->json();
        $json = is_array($json) ? $json : [];
        $error = is_array($json['error'] ?? null) ? $json['error'] : [];
        if ($response->successful()) {
            return new MetaDeliveryResult(true, false, 'accepted', $response->status(), requestSent: true, eventsReceived: is_numeric($json['events_received'] ?? null) ? (int) $json['events_received'] : null, fbtraceId: $this->safeText($json['fbtrace_id'] ?? null, 120, [$token, $testEventCode]), graphApiVersion: (string) config('meta.graph_api_version'), sourceUrl: $this->eventSourceUrl($events));
        }

        $status = $response->status();
        $details = [
            'requestSent' => true,
            'metaErrorCode' => $this->safeText($error['code'] ?? null, 80, [$token, $testEventCode]),
            'metaErrorSubcode' => $this->safeText($error['error_subcode'] ?? null, 80, [$token, $testEventCode]),
            'metaMessage' => $this->safeText($error['message'] ?? null, 500, [$token, $testEventCode]),
            'fbtraceId' => $this->safeText($error['fbtrace_id'] ?? null, 120, [$token, $testEventCode]),
        ];
        if (in_array($status, [408, 425, 429], true) || $status >= 500) {
            return new MetaDeliveryResult(
                false,
                true,
                $status === 429 ? 'meta_rate_limited' : 'network_error',
                $status,
                $this->retryAfter($response->header('Retry-After')),
                $details['requestSent'],
                null,
                $details['metaErrorCode'],
                $details['metaErrorSubcode'],
                $details['metaMessage'],
                $details['fbtraceId'],
                (string) config('meta.graph_api_version'),
                $this->eventSourceUrl($events),
            );
        }

        return new MetaDeliveryResult(
            false,
            false,
            'meta_rejected',
            $status,
            null,
            $details['requestSent'],
            null,
            $details['metaErrorCode'],
            $details['metaErrorSubcode'],
            $details['metaMessage'],
            $details['fbtraceId'],
            (string) config('meta.graph_api_version'),
            $this->eventSourceUrl($events),
        );
    }

    /** @return array<string, mixed> */
    private function payload(MetaEvent $event): array
    {
        $summary = $this->contextSummary($event);
        $eventTime = $event->getAttribute('event_time');
        if (! $eventTime instanceof CarbonInterface) {
            throw new \LogicException('Meta events require a valid original timestamp.');
        }
        $contents = $this->contents($summary);
        $contentIds = array_values(array_unique(array_filter(array_map(static fn (mixed $id): ?string => is_string($id) && $id !== '' ? $id : null, is_array($summary['content_ids'] ?? null) ? $summary['content_ids'] : []))));
        $customData = array_filter([
            'content_type' => $contentIds !== [] ? 'product' : null,
            'content_ids' => $contentIds !== [] ? $contentIds : null,
            'contents' => $contents !== [] ? $contents : null,
            'num_items' => isset($summary['item_count']) ? (int) $summary['item_count'] : null,
            'search_string' => $event->event_name === 'Search' ? ($summary['search_term'] ?? null) : null,
            'value' => isset($summary['value_millimes']) ? number_format(((int) $summary['value_millimes']) / 1000, 3, '.', '') : null,
            'currency' => isset($summary['value_millimes']) ? 'TND' : null,
        ], static fn (mixed $value): bool => $value !== null && $value !== []);

        $actionSource = in_array($summary['action_source'] ?? null, ['phone_call', 'chat', 'other'], true)
            ? $summary['action_source']
            : 'website';

        return [
            'event_name' => $event->event_name,
            'event_time' => $eventTime->getTimestamp(),
            'event_id' => $event->event_id,
            'action_source' => $actionSource,
            ...($event->source_url !== '' ? ['event_source_url' => $event->source_url] : []),
            'user_data' => $this->userData($event),
            ...($customData !== [] ? ['custom_data' => $customData] : []),
        ];
    }

    /** @param array<string, mixed> $summary
     * @return array<int, array{id: string, quantity: int, item_price: string}>
     */
    private function contents(array $summary): array
    {
        $rows = is_array($summary['contents'] ?? null) ? $summary['contents'] : [];

        return array_values(array_filter(array_map(static function (mixed $row): ?array {
            if (! is_array($row) || ! is_string($row['id'] ?? null) || $row['id'] === '') {
                return null;
            }
            $price = (int) ($row['item_price_millimes'] ?? 0);

            return ['id' => $row['id'], 'quantity' => max(1, (int) ($row['quantity'] ?? 1)), 'item_price' => number_format($price / 1000, 3, '.', '')];
        }, $rows)));
    }

    /** @return array<string, string> */
    private function userData(MetaEvent $event): array
    {
        $userData = [];
        if (is_string($event->user_data_encrypted) && $event->user_data_encrypted !== '') {
            try {
                $decoded = json_decode(Crypt::decryptString($event->user_data_encrypted), true, flags: JSON_THROW_ON_ERROR);
                $userData = is_array($decoded) ? array_intersect_key($decoded, array_flip(['client_ip_address', 'client_user_agent', 'fbp', 'fbc', 'ph'])) : [];
            } catch (DecryptException|\JsonException) {
                $userData = [];
            }
        }

        return $userData;
    }

    private function validPixelId(?string $pixelId): bool
    {
        return is_string($pixelId) && preg_match('/^\d{5,30}$/', $pixelId) === 1;
    }

    private function retryAfter(?string $header): ?int
    {
        return is_string($header) && ctype_digit($header) ? min((int) $header, 3600) : null;
    }

    /** @return array<string, mixed> */
    private function contextSummary(MetaEvent $event): array
    {
        $raw = $event->getRawOriginal('context_summary');
        if (! is_string($raw) || $raw === '') {
            return [];
        }

        try {
            $summary = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($summary) ? $summary : [];
    }

    /** @param array<int, string|null> $secrets */
    private function safeText(mixed $value, int $maxLength, array $secrets = []): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $text = strip_tags((string) $value);
        foreach ($secrets as $secret) {
            if (is_string($secret) && $secret !== '') {
                $text = str_replace($secret, '[redacted]', $text);
            }
        }

        return mb_substr($text, 0, $maxLength);
    }

    private function testSourceUrl(): string
    {
        $configured = config('meta.test_event_source_url');
        $url = is_string($configured) && trim($configured) !== '' ? $configured : (string) config('app.url');
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['host'])) {
            $parts = parse_url((string) config('app.url'));
        }
        if (! is_array($parts) || ! isset($parts['host'])) {
            throw new \LogicException('APP_URL must be a valid absolute URL for Meta connection tests.');
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (! in_array($scheme, ['http', 'https'], true) || (! app()->environment(['local', 'testing']) && $scheme !== 'https')) {
            throw new \LogicException('Meta connection tests require an HTTPS source URL outside local environments.');
        }
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path = '/'.ltrim((string) ($parts['path'] ?? ''), '/');

        return rtrim($scheme.'://'.$parts['host'].$port.$path, '/').'/';
    }

    /** @param array<int, array<string, mixed>> $events */
    private function eventSourceUrl(array $events): ?string
    {
        $sourceUrl = $events[0]['event_source_url'] ?? null;

        return is_string($sourceUrl) ? $sourceUrl : null;
    }
}
