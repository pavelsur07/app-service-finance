<?php

declare(strict_types=1);

namespace App\Budget\Application\DTO;

/**
 * Прочитанный файл плана (BudgetPlanXlsxReader): служебная строка выгрузки и строки таблицы.
 */
final readonly class BudgetPlanFile
{
    /**
     * @param list<BudgetPlanFileRow> $rows
     */
    public function __construct(
        public ?int $year,
        public ?string $projectDirectionId,
        public ?string $responsibilityCenterId,
        public array $rows,
    ) {
    }
}
