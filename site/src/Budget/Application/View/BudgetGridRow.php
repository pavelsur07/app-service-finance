<?php

declare(strict_types=1);

namespace App\Budget\Application\View;

/**
 * Строка сетки бюджета: статья дерева ОПиУ. Суммы — строки в знаке отчёта: деньги с двумя
 * знаками, показатели (`format` не `MONEY`) — с четырьмя. `format` — `PLValueFormat`.
 */
final readonly class BudgetGridRow
{
    /**
     * @param array<string, string> $values `Y-m` → сумма
     * @param array<string, string> $comments `Y-m` → комментарий (только при выбранной паре)
     */
    public function __construct(
        public string $id,
        public ?string $code,
        public string $name,
        public int $level,
        public string $type,
        public string $flow,
        public string $format,
        public bool $editable,
        public array $values,
        public string $total,
        public array $comments,
        /** Правило «X % от строки-базы» статьи в выбранной паре; такая строка не редактируется. */
        public ?BudgetGridRule $rule = null,
    ) {
    }
}
