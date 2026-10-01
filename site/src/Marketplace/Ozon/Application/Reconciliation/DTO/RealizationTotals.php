<?php

declare(strict_types=1);

namespace App\Marketplace\Ozon\Application\Reconciliation\DTO;

use App\Shared\Domain\ValueObject\Money;

/**
 * Итоги отчёта «Реализация» за период. База — цена покупателя (`sale_price`).
 */
final readonly class RealizationTotals
{
    public function __construct(
        public Money $sales,
        public int $salesQuantity,
        public Money $returns,
        public int $returnsQuantity,
    ) {
    }
}
