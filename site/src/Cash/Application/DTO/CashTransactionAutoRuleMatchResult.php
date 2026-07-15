<?php

declare(strict_types=1);

namespace App\Cash\Application\DTO;

use App\Cash\Entity\Transaction\CashTransactionAutoRule;

final readonly class CashTransactionAutoRuleMatchResult
{
    /**
     * @param list<CashTransactionAutoRule> $conflictingRules
     * @param list<CashTransactionAutoRule> $matchingRules
     * @param array<string, CashTransactionAutoRule> $winners
     * @param array<string, list<CashTransactionAutoRule>> $conflicts
     */
    public function __construct(
        public ?CashTransactionAutoRule $rule,
        public array $conflictingRules = [],
        public array $matchingRules = [],
        public array $winners = [],
        public array $conflicts = [],
    ) {
    }

    public function hasConflict(): bool
    {
        return [] !== $this->conflictingRules;
    }

    public function hasWinners(): bool
    {
        return [] !== $this->winners;
    }

    public function isWinner(CashTransactionAutoRule $rule): bool
    {
        return $this->hasWinnerId((string) $rule->getId());
    }

    public function hasWinnerId(string $ruleId): bool
    {
        foreach ($this->winners as $winner) {
            if ($winner->getId() === $ruleId) {
                return true;
            }
        }

        return false;
    }

    public function isConflictingRule(CashTransactionAutoRule $rule): bool
    {
        foreach ($this->conflictingRules as $conflictingRule) {
            if ($conflictingRule->getId() === $rule->getId()) {
                return true;
            }
        }

        return false;
    }
}
