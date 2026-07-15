<?php

declare(strict_types=1);

namespace App\Cash\Application\DTO;

use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Entity\Transaction\CashTransactionAutoRule;
use App\Company\Entity\Counterparty;
use App\Company\Entity\ProjectDirection;

final readonly class CashTransactionAutoRuleApplicationPlan
{
    /**
     * @param array<string, array{before: ?string, after: ?string}> $changes
     */
    public function __construct(
        public CashTransactionAutoRule $rule,
        public array $changes,
        public ?CashflowCategory $cashflowCategory,
        public ?ProjectDirection $projectDirection,
        public ?Counterparty $counterparty,
    ) {
    }

    public function hasChanges(): bool
    {
        return [] !== $this->changes;
    }

    /** @return array{autoRule: array{id: ?string, revision: int}, changes: array<string, array{before: ?string, after: ?string}>} */
    public function auditDiff(): array
    {
        return [
            'autoRule' => [
                'id' => $this->rule->getId(),
                'revision' => $this->rule->getRevision(),
            ],
            'changes' => $this->changes,
        ];
    }
}
