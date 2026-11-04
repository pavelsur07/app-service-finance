<?php

declare(strict_types=1);

namespace App\Budget\Application\DTO;

final readonly class ImportBudgetCellsCommand
{
    public function __construct(
        public string $companyId,
        public string $versionId,
        public string $projectDirectionId,
        public string $responsibilityCenterId,
        public BudgetPlanFile $file,
        public bool $apply,
        public bool $confirmOverwrite,
    ) {
    }
}
