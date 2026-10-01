<?php

declare(strict_types=1);

namespace App\Marketplace\Ozon\Application\Reconciliation\DTO;

use App\Marketplace\Enum\OzonReconciliationStatus;

final readonly class OzonReconciliationResult
{
    /**
     * @param list<ReconciledLine> $lines
     */
    public function __construct(
        public array $lines,
        public OzonReconciliationStatus $overall,
        public int $mismatchCount,
    ) {
    }
}
