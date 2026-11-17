<?php

declare(strict_types=1);

namespace App\Cash\Application\DTO;

/**
 * Строка консолидированного отчёта ДДС: статья эталона (`key` — код или `sys:<systemCode>`
 * у секции), «Не сопоставлено» или «Итого». Суммы — с подстатьями, со знаком направления.
 * total = сумма компаний минус внутригрупповые.
 */
final readonly class CashflowGroupReportLine
{
    /**
     * @param array<string, string> $amounts companyId => сумма, в порядке колонок отчёта
     */
    public function __construct(
        public string $key,
        public string $name,
        public int $level,
        public bool $section,
        public array $amounts,
        public string $intragroup,
        public string $total,
    ) {
    }
}
