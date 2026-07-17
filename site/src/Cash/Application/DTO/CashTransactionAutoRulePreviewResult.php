<?php

declare(strict_types=1);

namespace App\Cash\Application\DTO;

use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleSkipReason;

final readonly class CashTransactionAutoRulePreviewResult
{
    /**
     * @param list<array{
     *     transaction: CashTransaction,
     *     skipReason: CashTransactionAutoRuleSkipReason|null,
     *     match: CashTransactionAutoRuleMatchResult,
     *     plan: CashTransactionAutoRuleApplicationPlan|null
     * }> $rows
     * @param array{scanned: int, matched: int, wouldChange: int, noChange: int, skipped: int, conflicts: int} $summary
     * @param array{cashflowCategory: int, projectDirection: int, responsibilityCenterId: int, counterparty: int} $changesByField
     * @param list<array{key: string, label: string, matched: int, wouldChange: int, conflicts: int, skipped: int}> $byMonth
     * @param list<array{key: string, label: string, matched: int, wouldChange: int, conflicts: int, skipped: int}> $byCurrency
     * @param list<array{key: string, label: string, matched: int, wouldChange: int, conflicts: int, skipped: int}> $byCategory
     * @param list<array{key: string, label: string, matched: int, wouldChange: int, conflicts: int, skipped: int}> $byProject
     * @param list<array{key: string, label: string, matched: int, wouldChange: int, conflicts: int, skipped: int}> $byResponsibilityCenter
     * @param array<string, string> $responsibilityCenterLabels
     */
    public function __construct(
        public array $rows,
        public array $summary,
        public array $changesByField,
        public array $byMonth,
        public array $byCurrency,
        public array $byCategory,
        public array $byProject,
        public array $byResponsibilityCenter = [],
        public array $responsibilityCenterLabels = [],
    ) {
    }
}
