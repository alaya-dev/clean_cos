<?php

namespace App\Domain\MetaTracking\Services;

class MetaDeliveryResult
{
    public function __construct(
        public readonly bool $accepted,
        public readonly bool $temporary,
        public readonly string $classification,
        public readonly ?int $httpStatus = null,
        public readonly ?int $retryAfterSeconds = null,
        public readonly bool $requestSent = false,
        public readonly ?int $eventsReceived = null,
        public readonly ?string $metaErrorCode = null,
        public readonly ?string $metaErrorSubcode = null,
        public readonly ?string $metaMessage = null,
        public readonly ?string $fbtraceId = null,
        public readonly ?string $graphApiVersion = null,
        public readonly ?string $sourceUrl = null,
    ) {}

    /** @return array<string, mixed> */
    public function diagnostics(): array
    {
        return [
            'request_sent' => $this->requestSent,
            'http_status' => $this->httpStatus,
            'events_received' => $this->eventsReceived,
            'error_code' => $this->metaErrorCode,
            'error_subcode' => $this->metaErrorSubcode,
            'message' => $this->metaMessage,
            'fbtrace_id' => $this->fbtraceId,
            'classification' => $this->classification,
            'graph_api_version' => $this->graphApiVersion,
            'source_url' => $this->sourceUrl,
        ];
    }
}
