<?php

declare(strict_types=1);

namespace App\Ingestion\Application\DTO;

final readonly class FinancialSummaryMonthView
{
    public function __construct(
        public int $year,
        public int $month,
        public int $incomeMinor,
        public int $expenseMinor,
        public int $netMinor,
        public string $currency,
    ) {
    }

    /**
     * @return array{year: int, month: int, income_minor: int, expense_minor: int, net_minor: int, currency: string}
     */
    public function toArray(): array
    {
        return [
            'year' => $this->year,
            'month' => $this->month,
            'income_minor' => $this->incomeMinor,
            'expense_minor' => $this->expenseMinor,
            'net_minor' => $this->netMinor,
            'currency' => $this->currency,
        ];
    }
}
