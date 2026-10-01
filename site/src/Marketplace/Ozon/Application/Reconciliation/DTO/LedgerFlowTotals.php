<?php

declare(strict_types=1);

namespace App\Marketplace\Ozon\Application\Reconciliation\DTO;

use App\Shared\Domain\ValueObject\Money;

/**
 * Продажи и возвраты в учёте (`marketplace_sales`, `marketplace_returns`), база — цена продавца.
 */
final readonly class LedgerFlowTotals
{
    public function __construct(
        public Money $sales,
        public int $salesCount,
        public Money $returns,
        public int $returnsCount,
    ) {
    }
}
