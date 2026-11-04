<?php

declare(strict_types=1);

namespace App\Budget\Application\DTO;

final readonly class RenameBudgetVersionCommand
{
    public function __construct(
        public string $companyId,
        public string $versionId,
        public string $name,
    ) {
    }
}
