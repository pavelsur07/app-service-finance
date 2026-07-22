<?php

declare(strict_types=1);

namespace App\Cash\Application\DTO;

use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Entity\Transaction\CashTransactionAutoRule;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleApplyMode;
use App\Cash\Enum\Transaction\CashTransactionAutoRulePairIssue;
use App\Company\Entity\Counterparty;
use App\Company\Entity\ProjectDirection;

final readonly class CashTransactionAutoRuleApplicationPlan
{
    /**
     * @param array<string, array{before: ?string, after: ?string}> $changes
     * @param array<string, CashTransactionAutoRule> $rulesByField
     */
    public function __construct(
        public CashTransactionAutoRule $rule,
        public array $changes,
        public ?CashflowCategory $cashflowCategory,
        public ?ProjectDirection $projectDirection,
        public ?Counterparty $counterparty,
        public array $rulesByField = [],
        public ?string $responsibilityCenterId = null,
        public ?CashTransactionAutoRulePairIssue $pairIssue = null,
    ) {
    }

    public function hasChanges(): bool
    {
        return [] !== $this->changes;
    }

    /** @return array{correlationId: string, mode: string, autoRules: array<string, array{id: ?string, revision: int}>, changes: array<string, array{before: ?string, after: ?string}>} */
    public function auditDiff(
        string $correlationId,
        CashTransactionAutoRuleApplyMode $mode = CashTransactionAutoRuleApplyMode::SAFE,
    ): array {
        $rulesByField = $this->rulesByField;
        if ([] === $rulesByField) {
            foreach (array_keys($this->changes) as $field) {
                $rulesByField[$field] = $this->rule;
            }
        }

        return [
            'correlationId' => $correlationId,
            'mode' => $mode->value,
            'autoRules' => array_map(
                static fn (CashTransactionAutoRule $rule): array => [
                    'id' => $rule->getId(),
                    'revision' => $rule->getRevision(),
                ],
                $rulesByField,
            ),
            'changes' => $this->changes,
        ];
    }
}
