<?php

namespace App\Domain\FirstDelivery\Services;

final readonly class FirstDeliveryResult
{
    /** @param array<string, mixed>|array<int, mixed>|null $payload */
    public function __construct(
        public bool $accepted,
        public bool $temporary,
        public bool $uncertain,
        public bool $requestSent,
        public ?int $httpStatus,
        public string $classification,
        public ?string $safeMessage,
        public ?array $payload,
        public int $durationMs,
        public ?string $barcode = null,
        public ?string $printUrl = null,
    ) {}
}
