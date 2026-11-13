<?php

declare(strict_types=1);

namespace App\Cash\Application\Service\AutoRule;

use App\Cash\Application\DTO\CashTransactionAutoRuleMatchResult;
use App\Cash\Application\DTO\CashTransactionAutoRulePreviewResult;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Entity\Transaction\CashTransactionAutoRule;
use App\Cash\Repository\Transaction\CashTransactionAutoRuleRepository;
use App\Company\Application\DTO\FinancialResponsibilityCenterProjectDTO;
use App\Company\Facade\FinancialResponsibilityCenterFacade;

final readonly class CashAutoRulePreviewBuilder
{
    public function __construct(
        private CashTransactionAutoRuleRepository $ruleRepo,
        private FinancialResponsibilityCenterFacade $responsibilityCenterFacade,
        private CashAutoRuleMatcher $matcher,
        private CashAutoRuleApplicationPlanner $planner,
    ) {
    }

    /** @return array<string, mixed> */
    public function forTransaction(CashTransaction $transaction): array
    {
        $skipReason = $this->matcher->getSkipReason($transaction);
        $match = null === $skipReason ? $this->matcher->match($transaction, array_values($this->ruleRepo->findActiveByCompany($transaction->getCompany()))) : null;
        $hasPairConflict = null !== $match && $this->planner->hasPairConflict($match);
        $plan = null !== $match && ($match->hasWinners() || $hasPairConflict) ? $this->planner->create($match, $transaction) : null;
        $labels = [];
        foreach ($this->responsibilityCenterFacade->getActiveChoices((string) $transaction->getCompany()->getId()) as $center) {
            $labels[$center->id] = $center->name;
        }

        return ['transaction' => $transaction, 'rule' => $match?->rule, 'conflictingRules' => $match->conflictingRules ?? [], 'conflicts' => $match->conflicts ?? [], 'skipReason' => $skipReason, 'plan' => $plan, 'responsibilityCenterLabels' => $labels];
    }

    /** @param iterable<CashTransaction> $transactions */
    public function forRule(
        CashTransactionAutoRule $rule,
        iterable $transactions,
        int $limit,
    ): CashTransactionAutoRulePreviewResult {
        $rows = [];
        $summary = [
            'scanned' => 0,
            'matched' => 0,
            'wouldChange' => 0,
            'noChange' => 0,
            'skipped' => 0,
            'conflicts' => 0,
        ];
        $changesByField = [
            'cashflowCategory' => 0,
            'projectDirection' => 0,
            'responsibilityCenterId' => 0,
            'counterparty' => 0,
        ];
        $byMonth = [];
        $byCurrency = [];
        $byCategory = [];
        $byProject = [];
        $byResponsibilityCenter = [];
        $activeRules = array_values($this->ruleRepo->findActiveByCompany($rule->getCompany()));
        $companyId = (string) $rule->getCompany()->getId();
        $needsPairSnapshot = $this->planner->requiresPairSnapshot($activeRules);
        $activePairs = $needsPairSnapshot
            ? $this->responsibilityCenterFacade->getActivePairs($companyId)
            : [];
        $responsibilityCenterLabels = $needsPairSnapshot
            ? $this->responsibilityCenterLabels($activePairs)
            : $this->activeResponsibilityCenterLabels($companyId);

        foreach ($transactions as $transaction) {
            ++$summary['scanned'];
            if (!$this->matcher->matches($rule, $transaction)) {
                continue;
            }

            ++$summary['matched'];
            $skipReason = $this->matcher->getSkipReason($transaction);
            $match = null === $skipReason
                ? $this->matcher->match($transaction, $activeRules)
                : new CashTransactionAutoRuleMatchResult(null);
            $plan = $match->hasWinners() || $this->planner->hasPairConflict($match)
                ? $this->planner->create($match, $transaction, $activePairs)
                : null;
            $wouldChange = null !== $plan && $plan->hasChanges();
            $hasConflict = $match->hasConflict();
            $skipped = null !== $skipReason;

            ++$summary[$wouldChange ? 'wouldChange' : 'noChange'];
            $summary['skipped'] += (int) $skipped;
            $summary['conflicts'] += (int) $hasConflict;
            foreach (array_keys($plan->changes ?? []) as $field) {
                if (array_key_exists($field, $changesByField)) {
                    ++$changesByField[$field];
                }
            }

            $resultCategory = null !== $plan && array_key_exists('cashflowCategory', $plan->changes)
                ? $plan->cashflowCategory
                : $transaction->getCashflowCategory();
            $resultProject = null !== $plan && array_key_exists('projectDirection', $plan->changes)
                ? $plan->projectDirection
                : $transaction->getProjectDirection();
            $resultResponsibilityCenterId = null !== $plan && array_key_exists('responsibilityCenterId', $plan->changes)
                ? $plan->responsibilityCenterId
                : $transaction->getResponsibilityCenterId();

            $this->incrementBreakdown(
                $byMonth,
                $transaction->getOccurredAt()->format('Y-m'),
                $transaction->getOccurredAt()->format('m.Y'),
                $wouldChange,
                $hasConflict,
                $skipped,
            );
            $this->incrementBreakdown(
                $byCurrency,
                $transaction->getCurrency(),
                $transaction->getCurrency(),
                $wouldChange,
                $hasConflict,
                $skipped,
            );
            $this->incrementBreakdown(
                $byCategory,
                (string) ($resultCategory?->getId() ?? '__none__'),
                $resultCategory?->getName() ?? 'Не задано',
                $wouldChange,
                $hasConflict,
                $skipped,
            );
            $this->incrementBreakdown(
                $byProject,
                (string) ($resultProject?->getId() ?? '__none__'),
                $resultProject?->getName() ?? 'Не задано',
                $wouldChange,
                $hasConflict,
                $skipped,
            );
            $this->incrementBreakdown(
                $byResponsibilityCenter,
                $resultResponsibilityCenterId ?? '__none__',
                null === $resultResponsibilityCenterId
                    ? 'Не задано'
                    : ($responsibilityCenterLabels[$resultResponsibilityCenterId] ?? $resultResponsibilityCenterId),
                $wouldChange,
                $hasConflict,
                $skipped,
            );

            if (count($rows) >= $limit) {
                continue;
            }

            $rows[] = [
                'transaction' => $transaction,
                'skipReason' => $skipReason,
                'match' => $match,
                'plan' => $plan,
            ];
        }

        krsort($byMonth, \SORT_STRING);
        ksort($byCurrency, \SORT_STRING);
        uasort($byCategory, static fn (array $left, array $right): int => strnatcmp(mb_strtolower($left['label']), mb_strtolower($right['label'])));
        uasort($byProject, static fn (array $left, array $right): int => strnatcmp(mb_strtolower($left['label']), mb_strtolower($right['label'])));
        uasort($byResponsibilityCenter, static fn (array $left, array $right): int => strnatcmp(mb_strtolower($left['label']), mb_strtolower($right['label'])));

        return new CashTransactionAutoRulePreviewResult(
            $rows,
            $summary,
            $changesByField,
            array_values($byMonth),
            array_values($byCurrency),
            array_values($byCategory),
            array_values($byProject),
            array_values($byResponsibilityCenter),
            $responsibilityCenterLabels,
        );
    }

    /**
     * @param array<string, array{key: string, label: string, matched: int, wouldChange: int, conflicts: int, skipped: int}> $breakdown
     */
    private function incrementBreakdown(
        array &$breakdown,
        string $key,
        string $label,
        bool $wouldChange,
        bool $hasConflict,
        bool $skipped,
    ): void {
        $breakdown[$key] ??= [
            'key' => $key,
            'label' => $label,
            'matched' => 0,
            'wouldChange' => 0,
            'conflicts' => 0,
            'skipped' => 0,
        ];
        ++$breakdown[$key]['matched'];
        $breakdown[$key]['wouldChange'] += (int) $wouldChange;
        $breakdown[$key]['conflicts'] += (int) $hasConflict;
        $breakdown[$key]['skipped'] += (int) $skipped;
    }

    /**
     * @param list<FinancialResponsibilityCenterProjectDTO> $activePairs
     *
     * @return array<string, string>
     */
    private function responsibilityCenterLabels(array $activePairs): array
    {
        $labels = [];
        foreach ($activePairs as $pair) {
            if (null !== $pair->responsibilityCenterName) {
                $labels[$pair->responsibilityCenterId] = $pair->responsibilityCenterName;
            }
        }

        return $labels;
    }

    /** @return array<string, string> */
    private function activeResponsibilityCenterLabels(string $companyId): array
    {
        $labels = [];
        foreach ($this->responsibilityCenterFacade->getActiveChoices($companyId) as $center) {
            $labels[$center->id] = $center->name;
        }

        return $labels;
    }
}
