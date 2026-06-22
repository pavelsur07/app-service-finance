<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Source\Wildberries;

final readonly class WbFinancePreviewResult
{
    /**
     * @param list<WbFinancePreviewTransaction> $transactions
     * @param list<WbFinancePreviewRowCheck>    $rowChecks
     * @param list<WbFinancePreviewUnknownRow>  $unknownRows
     */
    public function __construct(
        public array $transactions,
        public array $rowChecks,
        public array $unknownRows,
        public int $scannedRows,
        public int $emptyRows,
    ) {
    }
}
