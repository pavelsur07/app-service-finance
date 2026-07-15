<?php

namespace App\Cash\Service\Transaction;

use App\Cash\Application\DTO\CashTransactionAutoRuleApplicationPlan;
use App\Cash\Application\DTO\CashTransactionAutoRuleMatchResult;
use App\Cash\Application\DTO\CashTransactionAutoRulePreviewResult;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Entity\Transaction\CashTransactionAutoRule;
use App\Cash\Enum\Transaction\CashDirection;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleConditionField;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleConditionOperator;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleOperationType;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleSkipReason;
use App\Cash\Repository\Transaction\CashTransactionAutoRuleRepository;
use App\Company\Entity\Company;
use App\Util\StringNormalizer;

class CashTransactionAutoRuleService
{
    public function __construct(
        private CashTransactionAutoRuleRepository $ruleRepo,
    ) {
    }

    /**
     * Найти основное правило-победитель для транзакции (в пределах компании транзакции).
     * Возвращает null, если ни у одного поля нет однозначного победителя.
     */
    public function findMatchingRule(CashTransaction $t): ?CashTransactionAutoRule
    {
        return $this->match($t)->rule;
    }

    public function match(CashTransaction $t): CashTransactionAutoRuleMatchResult
    {
        if (null !== $this->getSkipReason($t)) {
            return new CashTransactionAutoRuleMatchResult(null);
        }

        return $this->resolveMatch(
            $t,
            $this->ruleRepo->findActiveByCompany($t->getCompany()),
        );
    }

    /** @param iterable<CashTransaction> $transactions */
    public function previewRule(
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
            'counterparty' => 0,
        ];
        $byMonth = [];
        $byCurrency = [];
        $byCategory = [];
        $byProject = [];
        $activeRules = $this->ruleRepo->findActiveByCompany($rule->getCompany());

        foreach ($transactions as $transaction) {
            ++$summary['scanned'];
            if (!$this->ruleMatchesTransaction($rule, $transaction)) {
                continue;
            }

            ++$summary['matched'];
            $skipReason = $this->getSkipReason($transaction);
            $match = null === $skipReason
                ? $this->resolveMatch($transaction, $activeRules)
                : new CashTransactionAutoRuleMatchResult(null);
            $plan = $match->hasWinners()
                ? $this->createApplicationPlan($match, $transaction)
                : null;
            $wouldChange = null !== $plan && $plan->hasChanges();
            $hasConflict = $match->hasConflict();
            $skipped = null !== $skipReason;

            ++$summary[$wouldChange ? 'wouldChange' : 'noChange'];
            $summary['skipped'] += (int) $skipped;
            $summary['conflicts'] += (int) $hasConflict;
            foreach (array_keys($plan?->changes ?? []) as $field) {
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
        uasort($byCategory, static fn (array $left, array $right): int => strnatcasecmp($left['label'], $right['label']));
        uasort($byProject, static fn (array $left, array $right): int => strnatcasecmp($left['label'], $right['label']));

        return new CashTransactionAutoRulePreviewResult(
            $rows,
            $summary,
            $changesByField,
            array_values($byMonth),
            array_values($byCurrency),
            array_values($byCategory),
            array_values($byProject),
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

    /** @param list<CashTransactionAutoRule> $rules */
    private function resolveMatch(CashTransaction $transaction, array $rules): CashTransactionAutoRuleMatchResult
    {
        $matchingRules = array_values(array_filter(
            $rules,
            fn (CashTransactionAutoRule $rule): bool => $this->ruleMatchesTransaction($rule, $transaction),
        ));

        if ([] === $matchingRules) {
            return new CashTransactionAutoRuleMatchResult(null);
        }

        usort($matchingRules, function (CashTransactionAutoRule $left, CashTransactionAutoRule $right): int {
            return $right->getPriority() <=> $left->getPriority()
                ?: $this->getSpecificity($right) <=> $this->getSpecificity($left)
                ?: strcmp((string) $left->getId(), (string) $right->getId());
        });

        $winners = [];
        $conflicts = [];
        foreach (['cashflowCategory', 'projectDirection', 'counterparty'] as $field) {
            $candidates = array_values(array_filter(
                $matchingRules,
                fn (CashTransactionAutoRule $rule): bool => null !== $this->getTargetId($rule, $field),
            ));
            if ([] === $candidates) {
                continue;
            }

            $topRule = $candidates[0];
            $topSpecificity = $this->getSpecificity($topRule);
            $contenders = array_values(array_filter(
                $candidates,
                fn (CashTransactionAutoRule $rule): bool => $rule->getPriority() === $topRule->getPriority()
                    && $this->getSpecificity($rule) === $topSpecificity,
            ));
            $targetIds = array_unique(array_map(
                fn (CashTransactionAutoRule $rule): ?string => $this->getTargetId($rule, $field),
                $contenders,
            ));

            if (count($targetIds) > 1) {
                $conflicts[$field] = $contenders;
            } else {
                $winners[$field] = $topRule;
            }
        }

        $conflictingRules = [];
        foreach ($conflicts as $fieldConflicts) {
            foreach ($fieldConflicts as $conflictingRule) {
                $conflictingRules[(string) $conflictingRule->getId()] = $conflictingRule;
            }
        }

        return new CashTransactionAutoRuleMatchResult(
            $winners['cashflowCategory'] ?? $winners['projectDirection'] ?? $winners['counterparty'] ?? null,
            array_values($conflictingRules),
            $matchingRules,
            $winners,
            $conflicts,
        );
    }

    private function ruleMatchesTransaction(CashTransactionAutoRule $rule, CashTransaction $transaction): bool
    {
        if (!$this->belongsToSameCompany($rule->getCompany(), $transaction->getCompany())
            || !$this->hasConsistentCompanyScope($rule)) {
            return false;
        }

        $operationType = $rule->getOperationType();
        if ('INFLOW' === $operationType->value && CashDirection::INFLOW !== $transaction->getDirection()) {
            return false;
        }
        if ('OUTFLOW' === $operationType->value && CashDirection::OUTFLOW !== $transaction->getDirection()) {
            return false;
        }

        foreach ($rule->getConditions() as $condition) {
            $operator = $condition->getOperator();
            $value = (string) ($condition->getValue() ?? '');
            $valueTo = (string) ($condition->getValueTo() ?? '');

            $matches = match ($condition->getField()) {
                CashTransactionAutoRuleConditionField::COUNTERPARTY => $transaction->getCounterparty() === $condition->getCounterparty(),
                CashTransactionAutoRuleConditionField::COUNTERPARTY_NAME => StringNormalizer::contains(
                    $transaction->getCounterparty()?->getName() ?? '',
                    $value,
                ),
                CashTransactionAutoRuleConditionField::INN => preg_replace('/\D+/', '', (string) ($transaction->getCounterparty()?->getInn() ?? ''))
                    === preg_replace('/\D+/', '', $value),
                CashTransactionAutoRuleConditionField::DATE => $this->dateMatches(
                    $transaction->getOccurredAt(),
                    $operator,
                    $value,
                    $valueTo,
                ),
                CashTransactionAutoRuleConditionField::AMOUNT => $this->amountMatches(
                    $transaction->getAmount(),
                    $operator,
                    $value,
                    $valueTo,
                ),
                CashTransactionAutoRuleConditionField::DESCRIPTION => StringNormalizer::contains(
                    $transaction->getDescription() ?? '',
                    $value,
                ),
            };

            if (!$matches) {
                return false;
            }
        }

        return true;
    }

    private function dateMatches(
        \DateTimeImmutable $date,
        CashTransactionAutoRuleConditionOperator $operator,
        string $value,
        string $valueTo,
    ): bool {
        $from = new \DateTimeImmutable($value.' 00:00:00');
        $to = new \DateTimeImmutable(
            (CashTransactionAutoRuleConditionOperator::BETWEEN === $operator ? $valueTo : $value).' 23:59:59',
        );

        return $date >= $from && $date <= $to;
    }

    private function amountMatches(
        string $amount,
        CashTransactionAutoRuleConditionOperator $operator,
        string $value,
        string $valueTo,
    ): bool {
        $value = str_replace(',', '.', trim($value));
        $valueTo = str_replace(',', '.', trim($valueTo));
        $comparedToValue = \bccomp($amount, $value, 2);

        return match ($operator) {
            CashTransactionAutoRuleConditionOperator::BETWEEN => $comparedToValue >= 0
                && \bccomp($amount, $valueTo, 2) <= 0,
            CashTransactionAutoRuleConditionOperator::GREATER_THAN => $comparedToValue > 0,
            CashTransactionAutoRuleConditionOperator::LESS_THAN => $comparedToValue < 0,
            default => 0 === $comparedToValue,
        };
    }

    private function getSpecificity(CashTransactionAutoRule $rule): int
    {
        return $rule->getConditions()->count()
            + (CashTransactionAutoRuleOperationType::ANY === $rule->getOperationType() ? 0 : 1);
    }

    private function getTargetId(CashTransactionAutoRule $rule, string $field): ?string
    {
        return match ($field) {
            'cashflowCategory' => $rule->getCashflowCategory()?->getId(),
            'projectDirection' => $rule->getProjectDirection()?->getId(),
            'counterparty' => $rule->getCounterparty()?->getId(),
        };
    }

    /** Применить текущее правило-победитель к одной транзакции без сохранения. */
    public function applyRule(
        CashTransactionAutoRule $rule,
        CashTransaction $t,
        ?CashTransactionAutoRuleMatchResult $resolvedMatch = null,
    ): ?CashTransactionAutoRuleApplicationPlan {
        if (null !== $this->getSkipReason($t)) {
            return null;
        }

        if (!$rule->isActive() || !$this->ruleMatchesTransaction($rule, $t)) {
            return null;
        }

        $resolvedMatch ??= $this->match($t);
        if (!$resolvedMatch->isWinner($rule)) {
            return null;
        }

        return $this->applyMatch($t, $resolvedMatch);
    }

    public function applyMatch(
        CashTransaction $transaction,
        ?CashTransactionAutoRuleMatchResult $resolvedMatch = null,
    ): ?CashTransactionAutoRuleApplicationPlan {
        if (null !== $this->getSkipReason($transaction)) {
            return null;
        }

        $resolvedMatch ??= $this->match($transaction);
        if (!$resolvedMatch->hasWinners()) {
            return null;
        }

        $plan = $this->createApplicationPlan($resolvedMatch, $transaction);
        $this->applyPlan($plan, $transaction);

        return $plan;
    }

    public function createApplicationPlan(
        CashTransactionAutoRuleMatchResult $match,
        CashTransaction $t,
    ): CashTransactionAutoRuleApplicationPlan {
        $changes = [];
        $rulesByField = [];
        $categoryRule = $match->winners['cashflowCategory'] ?? null;
        $projectRule = $match->winners['projectDirection'] ?? null;
        $counterpartyRule = $match->winners['counterparty'] ?? null;
        $category = $categoryRule?->getCashflowCategory();
        $projectDirection = $projectRule?->getProjectDirection();
        $counterparty = $counterpartyRule?->getCounterparty();

        $currentCategory = $t->getCashflowCategory();
        $isCategoryEmpty = null === $currentCategory || in_array(
            $currentCategory->getCode(),
            [CashflowCategory::CODE_UNALLOCATED, CashflowCategory::SYSTEM_UNALLOCATED],
            true,
        );
        if (null !== $categoryRule && null !== $category
            && $currentCategory?->getId() !== $category->getId() && $isCategoryEmpty) {
            $changes['cashflowCategory'] = [
                'before' => $currentCategory?->getId(),
                'after' => $category->getId(),
            ];
            $rulesByField['cashflowCategory'] = $categoryRule;
        }
        if (null === $t->getProjectDirection() && null !== $projectRule && null !== $projectDirection) {
            $changes['projectDirection'] = [
                'before' => null,
                'after' => $projectDirection->getId(),
            ];
            $rulesByField['projectDirection'] = $projectRule;
        }
        if (null === $t->getCounterparty() && null !== $counterpartyRule && null !== $counterparty) {
            $changes['counterparty'] = [
                'before' => null,
                'after' => $counterparty->getId(),
            ];
            $rulesByField['counterparty'] = $counterpartyRule;
        }

        $primaryRule = $match->rule;
        if (null === $primaryRule) {
            throw new \LogicException('An application plan requires at least one winning auto rule.');
        }

        return new CashTransactionAutoRuleApplicationPlan(
            $primaryRule,
            $changes,
            $category,
            $projectDirection,
            $counterparty,
            $rulesByField,
        );
    }

    private function applyPlan(CashTransactionAutoRuleApplicationPlan $plan, CashTransaction $transaction): void
    {
        if (array_key_exists('cashflowCategory', $plan->changes)) {
            $transaction->setCashflowCategory($plan->cashflowCategory);
        }

        if (array_key_exists('projectDirection', $plan->changes)) {
            $transaction->setProjectDirection($plan->projectDirection);
        }

        if (array_key_exists('counterparty', $plan->changes)) {
            $transaction->setCounterparty($plan->counterparty);
        }
    }

    private function hasConsistentCompanyScope(CashTransactionAutoRule $rule): bool
    {
        $company = $rule->getCompany();

        if (null !== $rule->getCashflowCategory()
            && !$this->belongsToSameCompany($company, $rule->getCashflowCategory()->getCompany())) {
            return false;
        }

        if (null !== $rule->getProjectDirection()
            && !$this->belongsToSameCompany($company, $rule->getProjectDirection()->getCompany())) {
            return false;
        }

        if (null !== $rule->getCounterparty()
            && !$this->belongsToSameCompany($company, $rule->getCounterparty()->getCompany())) {
            return false;
        }

        foreach ($rule->getConditions() as $condition) {
            if (null !== $condition->getCounterparty()
                && !$this->belongsToSameCompany($company, $condition->getCounterparty()->getCompany())) {
                return false;
            }
        }

        return true;
    }

    private function belongsToSameCompany(Company $left, Company $right): bool
    {
        return null !== $left->getId() && $left->getId() === $right->getId();
    }

    public function getSkipReason(CashTransaction $transaction): ?CashTransactionAutoRuleSkipReason
    {
        if ($transaction->isDeleted()) {
            return CashTransactionAutoRuleSkipReason::DELETED;
        }

        $lockBefore = $transaction->getCompany()->getFinanceLockBefore();
        if (null !== $lockBefore && $transaction->getOccurredAt()->setTime(0, 0) < $lockBefore->setTime(0, 0)) {
            return CashTransactionAutoRuleSkipReason::LOCKED_PERIOD;
        }

        return null;
    }
}
