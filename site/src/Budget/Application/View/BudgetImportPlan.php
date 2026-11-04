<?php

declare(strict_types=1);

namespace App\Budget\Application\View;

/**
 * Итог загрузки файла плана: ячейки — как у помощника (BudgetFillPlan), плюс предупреждения
 * (другой год или пара, пропущенные итоги) и строки файла, которые не загружаются.
 */
final readonly class BudgetImportPlan
{
    /**
     * @param list<string> $warnings
     * @param list<string> $ignored
     */
    public function __construct(
        public BudgetFillPlan $fill,
        public array $warnings,
        public array $ignored,
    ) {
    }
}
