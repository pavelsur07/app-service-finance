<?php

declare(strict_types=1);

namespace App\Ingestion\Application\DTO;

/**
 * Прочитанный отчёт «Детализация начислений»: строки и сколько строк пропущено
 * (нет даты, суммы, группы или типа) — dry-run должен показывать потери.
 */
final readonly class OzonAccrualDetailsReport
{
    /**
     * @param list<OzonAccrualDetailsRow> $rows
     */
    public function __construct(
        public array $rows,
        public int $skippedRows,
    ) {
    }
}
