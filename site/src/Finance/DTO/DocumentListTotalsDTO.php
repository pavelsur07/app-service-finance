<?php

declare(strict_types=1);

namespace App\Finance\DTO;

/**
 * Итог списка документов ОПиУ по всей выборке фильтра. Суммы — десятичные строки
 * из БД без знака: доход и расход положительны, результат = доход − расход.
 */
final readonly class DocumentListTotalsDTO
{
    public string $income;
    public string $expense;
    public string $result;

    public function __construct(
        public int $documents,
        public int $operations,
        string $income,
        string $expense,
    ) {
        // Пустая выборка приходит из SUM как «0»: два знака у всех сумм одинаково.
        $this->income = bcadd($income, '0', 2);
        $this->expense = bcadd($expense, '0', 2);
        $this->result = bcsub($this->income, $this->expense, 2);
    }
}
