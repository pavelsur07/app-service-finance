<?php

declare(strict_types=1);

namespace App\Ingestion\Application\DTO;

final readonly class PushResult
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public bool $accepted,
        public ?string $externalId = null,
        public array $metadata = [],
    ) {
    }
}
