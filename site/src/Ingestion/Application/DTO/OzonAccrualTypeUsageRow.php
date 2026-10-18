<?php

declare(strict_types=1);

namespace App\Ingestion\Application\DTO;

/**
 * Неразобранные начисления by-day одного типа Ozon по всем компаниям.
 * `transactionType` — самый частый тип транзакции этих строк.
 */
final readonly class OzonAccrualTypeUsageRow
{
    public function __construct(
        public int $typeId,
        public int $transactions,
        public string $firstDate,
        public string $lastDate,
        public string $transactionType,
    ) {
    }
}
