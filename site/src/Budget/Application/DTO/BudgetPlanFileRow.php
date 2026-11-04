<?php

declare(strict_types=1);

namespace App\Budget\Application\DTO;

/**
 * Строка таблицы файла плана (лист «План»): суммы — в знаке ввода, пустая ячейка — `0.00`,
 * не сумма (текст, формула, больше двух знаков) — null.
 */
final readonly class BudgetPlanFileRow
{
    /**
     * @param int $line номер строки листа
     * @param array<int, ?string> $amounts номер месяца 1–12 → сумма
     */
    public function __construct(
        public int $line,
        public ?string $plCategoryId,
        public ?string $code,
        public string $name,
        public array $amounts,
    ) {
    }
}
