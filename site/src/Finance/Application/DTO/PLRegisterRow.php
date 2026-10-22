<?php

declare(strict_types=1);

namespace App\Finance\Application\DTO;

/**
 * Строка регистра ОПиУ (`pl_daily_totals`): ожидаемая — из документов, фактическая — из таблицы.
 * Суммы — decimal-строки с двумя знаками, как их пишет `PLRegisterUpdater`.
 */
final readonly class PLRegisterRow
{
    public function __construct(
        public string $date,
        public ?string $categoryId,
        public string $projectDirectionId,
        public ?string $responsibilityCenterId,
        public string $income,
        public string $expense,
    ) {
    }

    /**
     * Ключ уникальности строки — тот же, что у частичных unique-индексов `pl_daily_totals`.
     */
    public function key(): string
    {
        return implode('|', [
            $this->date,
            $this->categoryId ?? '-',
            $this->projectDirectionId,
            $this->responsibilityCenterId ?? '-',
        ]);
    }
}
