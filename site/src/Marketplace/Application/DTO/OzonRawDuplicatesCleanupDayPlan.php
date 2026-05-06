<?php

declare(strict_types=1);

namespace App\Marketplace\Application\DTO;

final readonly class OzonRawDuplicatesCleanupDayPlan
{
    /**
     * @param list<string> $duplicateRawDocumentIds
     * @param list<string> $safeToDeleteRawDocumentIds
     * @param list<string> $warnings
     */
    public function __construct(
        public \DateTimeImmutable $day,
        public string $canonicalRawDocumentId,
        public array $duplicateRawDocumentIds,
        public int $staleSalesRowsCount,
        public int $staleReturnsRowsCount,
        public int $staleCostsRowsCount,
        public int $closedSalesRowsCount,
        public int $closedReturnsRowsCount,
        public int $closedCostsRowsCount,
        public bool $canAutoCleanup,
        public array $safeToDeleteRawDocumentIds,
        public array $warnings,
    ) {
    }
}
