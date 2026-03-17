<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Command;

use App\Marketplace\Enum\CloseStage;

/**
 * Команда закрытия одного этапа месяца маркетплейса.
 * Worker-safe: только scalar типы.
 */
final class CloseMonthStageCommand
{
    public function __construct(
        public readonly string $companyId,
        public readonly string $marketplace,
        public readonly int    $year,
        public readonly int    $month,
        public readonly string $stage,      // CloseStage::value — scalar для Message
        public readonly string $actorUserId,
    ) {
    }
}
