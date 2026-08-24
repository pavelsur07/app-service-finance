<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Api\Wildberries;

/** @param list<array<string,mixed>> $rows */
final readonly class WbFinanceSalesReportPage
{
    public function __construct(
        public array $rows,
        public ?int $nextRrdId,
        public bool $hasNextPage,
    ) {
    }
}
