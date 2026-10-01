<?php

declare(strict_types=1);

namespace App\Marketplace\Ozon\Application\Reconciliation\DTO;

use App\Shared\Domain\ValueObject\Money;

/**
 * Продажи и возвраты из сырых начислений by-day в двух базах Ozon:
 * `sale_price` (цена покупателя, как в «Реализации») и `sale_amount` (цена продавца, как в учёте).
 * Возвраты положительные.
 */
final readonly class RawFlowTotals
{
    public function __construct(
        public Money $salesBuyerBase,
        public Money $salesSellerBase,
        public int $salesCount,
        public Money $returnsBuyerBase,
        public Money $returnsSellerBase,
        public int $returnsCount,
    ) {
    }
}
