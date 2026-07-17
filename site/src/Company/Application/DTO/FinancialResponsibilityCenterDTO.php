<?php

declare(strict_types=1);

namespace App\Company\Application\DTO;

use App\Company\Enum\FinancialResponsibilityCenterStatus;

final readonly class FinancialResponsibilityCenterDTO
{
    public function __construct(
        public string $id,
        public string $code,
        public string $name,
        public int $sort,
        public string $status,
        public bool $system,
        public int $version,
    ) {
    }

    public function isActive(): bool
    {
        return FinancialResponsibilityCenterStatus::ACTIVE->value === $this->status;
    }
}
