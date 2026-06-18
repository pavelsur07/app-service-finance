<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Api\Ozon;

final readonly class OzonRawPage
{
    /**
     * @param list<array<string, mixed>> $rows
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public array $rows,
        public bool $hasMore,
        public ?string $nextPageToken = null,
        public array $metadata = [],
    ) {
    }
}
