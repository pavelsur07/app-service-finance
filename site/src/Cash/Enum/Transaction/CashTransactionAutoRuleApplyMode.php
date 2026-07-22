<?php

declare(strict_types=1);

namespace App\Cash\Enum\Transaction;

enum CashTransactionAutoRuleApplyMode: string
{
    case SAFE = 'safe';
    case REPLACE_AUTO_ASSIGNED = 'replace_auto_assigned';

    public function replacesAutoAssigned(): bool
    {
        return self::REPLACE_AUTO_ASSIGNED === $this;
    }
}
