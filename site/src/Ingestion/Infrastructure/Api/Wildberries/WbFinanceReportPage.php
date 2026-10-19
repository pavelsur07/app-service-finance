<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Api\Wildberries;

final readonly class WbFinanceReportPage
{
    /**
     * @param list<array<string, mixed>> $rows
     * @param array<string, mixed>       $metadata
     */
    public function __construct(
        public array $rows,
        public ?int $nextRrdId,
        public bool $hasMore,
        public array $metadata = [],
    ) {
    }
}
