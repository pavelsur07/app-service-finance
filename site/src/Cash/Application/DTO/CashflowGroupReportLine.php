<?php

declare(strict_types=1);

namespace App\Cash\Application\DTO;

/**
 * Строка консолидированного отчёта ДДС: статья эталона (`key` — код или `sys:<systemCode>`
 * у секции), «Не сопоставлено» или «Итого». Суммы — с подстатьями, со знаком направления.
 * total = сумма компаний плюс элиминация со знаком.
 */
final readonly class CashflowGroupReportLine
{
    /**
     * @param array<string, string> $amounts companyId => сумма, в порядке колонок отчёта
     * @param list<string> $pairIds исходящие операции пар этой ячейки
     */
    public function __construct(
        public string $key,
        public string $name,
        public int $level,
        public bool $section,
        public array $amounts,
        public string $elimination,
        public string $total,
        public bool $hasUnpaired,
        public array $pairIds,
    ) {
    }

    public function getIntragroup(): string
    {
        return bcsub('0.00', $this->elimination, 2);
    }
}
