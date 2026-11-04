<?php

declare(strict_types=1);

namespace App\Budget\Application\DTO;

/** Активная пара «проект × ЦФО» компании, выбранная в разрезе бюджета. */
final readonly class BudgetPair
{
    public function __construct(
        public string $projectDirectionId,
        public string $responsibilityCenterId,
    ) {
    }
}
