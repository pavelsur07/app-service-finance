<?php

declare(strict_types=1);

namespace App\Budget\Application\View;

/**
 * Строка сетки бюджета: статья дерева ОПиУ. Суммы — строки с двумя знаками в знаке отчёта.
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
        public bool $editable,
        public array $values,
        public string $total,
        public array $comments,
    ) {
    }
}
