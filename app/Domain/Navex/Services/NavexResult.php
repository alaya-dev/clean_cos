<?php

namespace App\Domain\Navex\Services;

final readonly class NavexResult
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
        public ?string $trackingCode,
        public ?array $payload,
        public int $durationMs,
    ) {}
}
