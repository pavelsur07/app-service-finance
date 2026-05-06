<?php

declare(strict_types=1);

namespace App\Marketplace\Application\DTO;

final readonly class OzonRawDuplicatesCleanupExecutionResult
{
    /** @param list<string> $cleanedCanonicalRawDocumentIds */
    public function __construct(
        public int $deletedSalesRows,
        public int $deletedReturnsRows,
        public int $deletedCostsRows,
        public int $cleanedDaysCount,
        public array $cleanedCanonicalRawDocumentIds,
    ) {
    }
}
