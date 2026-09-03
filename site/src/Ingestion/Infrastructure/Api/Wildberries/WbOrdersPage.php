<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Api\Wildberries;

final readonly class WbOrdersPage
{
    /**
     * @param list<array<string, mixed>> $rows
     * @param int|null $nextToken курсор постраничности WB (`next`), не отметка времени
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public array $rows,
        public bool $hasMore,
        public ?int $nextToken = null,
        public array $metadata = [],
    ) {
    }
}
