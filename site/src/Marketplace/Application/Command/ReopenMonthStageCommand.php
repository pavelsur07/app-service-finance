<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Command;

use App\Marketplace\Enum\CloseStage;

final class ReopenMonthStageCommand
{
    public function __construct(
        public readonly string     $companyId,
        public readonly string     $marketplace,
        public readonly int        $year,
        public readonly int        $month,
        public readonly CloseStage $stage,
    ) {
    }
}
