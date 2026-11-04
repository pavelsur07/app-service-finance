<?php

declare(strict_types=1);

namespace App\Budget\Application\DTO;

use App\Budget\Enum\BudgetVersionStatus;

final readonly class ChangeBudgetVersionStatusCommand
{
    public function __construct(
        public string $companyId,
        public string $versionId,
        public BudgetVersionStatus $target,
        public string $actorUserId,
    ) {
    }
}
