<?php

declare(strict_types=1);

namespace App\Company\Application\DTO;

final readonly class FinancialResponsibilityCenterProjectDTO
{
    public function __construct(
        public string $projectDirectionId,
        public string $responsibilityCenterId,
        public ?string $responsibilityCenterName = null,
        public bool $system = false,
    ) {
    }
}
