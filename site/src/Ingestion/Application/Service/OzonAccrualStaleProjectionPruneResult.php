<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Service;

final readonly class OzonAccrualStaleProjectionPruneResult
{
    /**
     * @param list<string> $affectedDates
     * @param list<array<string, mixed>> $rows
     */
    public function __construct(
        public int $candidates,
        public int $deleted,
        public array $affectedDates,
        public array $rows = [],
    ) {
    }
}
