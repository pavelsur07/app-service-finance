<?php

declare(strict_types=1);

namespace App\Marketplace\Ozon\Application\Reconciliation\DTO;

/**
 * Всё, что нужно калькулятору сверки: данные трёх источников за период.
 */
final readonly class OzonReconciliationInputs
{
    /**
     * @param array<string, CostBucket> $rawCosts код категории → нетто-расход по сырью
     * @param array<string, CostBucket> $ledgerCosts код категории → нетто-расход в учёте
     */
    public function __construct(
        public ?RealizationTotals $realization,
        public RawFlowTotals $rawFlows,
        public array $rawCosts,
        public int $rawDaysPresent,
        public int $rawDaysExpected,
        public LedgerFlowTotals $ledgerFlows,
        public array $ledgerCosts,
        public CostBucket $costsOutsideRaw,
    ) {
    }
}
