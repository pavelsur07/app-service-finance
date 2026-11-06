<?php

declare(strict_types=1);

namespace App\Inventory\Application\DTO;

/**
 * Итог отчёта «Остатки» по всем строкам текущего фильтра (источник, схема, дата),
 * а не только по странице. Значения — numeric-строки из SUM, как в строках отчёта.
 */
final readonly class StockReportTotals
{
    public function __construct(
        public string $quantity,
        public string $reservedQuantity,
        public string $availableForSale,
    ) {
    }
}
