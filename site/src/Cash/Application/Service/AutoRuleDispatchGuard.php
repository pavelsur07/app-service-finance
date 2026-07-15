<?php

namespace App\Cash\Application\Service;

use App\Cash\Application\DTO\CashTransactionAutoRuleApplicationPlan;

final class AutoRuleDispatchGuard
{
    private int $depth = 0;
    private ?CashTransactionAutoRuleApplicationPlan $applicationPlan = null;

    public function isSuppressed(): bool
    {
        return $this->depth > 0;
    }

    public function getApplicationPlan(): ?CashTransactionAutoRuleApplicationPlan
    {
        return $this->applicationPlan;
    }

    public function suppress(
        callable $operation,
        ?CashTransactionAutoRuleApplicationPlan $applicationPlan = null,
    ): mixed {
        $previousPlan = $this->applicationPlan;
        ++$this->depth;
        $this->applicationPlan = $applicationPlan ?? $previousPlan;

        try {
            return $operation();
        } finally {
            $this->applicationPlan = $previousPlan;
            --$this->depth;
        }
    }
}
