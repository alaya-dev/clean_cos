<?php

namespace App\Domain\MetaTracking\Services;

final readonly class MetaCatalogIdentifierResolution
{
    public function __construct(
        public ?string $identifier,
        public string $source,
    ) {}

    public function mapped(): bool
    {
        return $this->identifier !== null;
    }
}
