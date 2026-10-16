<?php

declare(strict_types=1);

namespace App\Finance\DTO;

/**
 * Итог списка документов ОПиУ в одной валюте. Суммы — десятичные строки:
 * доход и расход неотрицательны, результат = доход − расход.
 */
final readonly class DocumentCurrencyTotalsDTO
{
    public string $income;
    public string $expense;
    public string $result;

    public function __construct(
        public string $currency,
        string $income,
        string $expense,
    ) {
        $this->income = bcadd($income, '0', 2);
        $this->expense = bcadd($expense, '0', 2);
        $this->result = bcsub($this->income, $this->expense, 2);
    }
}
