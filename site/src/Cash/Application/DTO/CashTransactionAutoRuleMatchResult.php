<?php

declare(strict_types=1);

namespace App\Cash\Application\DTO;

use App\Cash\Entity\Transaction\CashTransactionAutoRule;

final readonly class CashTransactionAutoRuleMatchResult
{
    /** @param list<CashTransactionAutoRule> $conflictingRules */
    public function __construct(
        public ?CashTransactionAutoRule $rule,
        public array $conflictingRules = [],
    ) {
    }

    public function hasConflict(): bool
    {
        return [] !== $this->conflictingRules;
    }
}
