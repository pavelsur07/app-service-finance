<?php

declare(strict_types=1);

namespace App\Cash\Application\DTO;

final readonly class CashAiAutoApplyResult
{
    public function __construct(
        /** Причина, по которой ворота закрыты (`CashAiAutoApplyPolicy::REASON_*`); null — открыты. */
        public ?string $closedReason,
        public CashAiAutoApplyGateStats $gate,
        public int $applied = 0,
        public int $stale = 0,
    ) {
    }
}
