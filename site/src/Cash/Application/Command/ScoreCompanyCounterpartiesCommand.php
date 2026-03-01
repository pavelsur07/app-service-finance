<?php

declare(strict_types=1);

namespace App\Cash\Application\Command;

final readonly class ScoreCompanyCounterpartiesCommand
{
    public function __construct(
        public string $companyId,
    ) {}
}
