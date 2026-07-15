<?php

namespace App\Cash\Service\Transaction;

use App\Cash\Application\DTO\CashTransactionAutoRuleApplicationPlan;
use App\Cash\Application\DTO\CashTransactionAutoRuleMatchResult;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Entity\Transaction\CashTransactionAutoRule;
use App\Cash\Enum\Transaction\CashDirection;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleAction;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleConditionField;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleConditionOperator;
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
     * Найти правило-победитель для транзакции (в пределах компании транзакции).
     * Возвращает null, если правило не найдено или обнаружен конфликт.
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

    /**
     * @param iterable<CashTransaction> $transactions
     *
     * @return list<array{
     *     transaction: CashTransaction,
     *     skipReason: CashTransactionAutoRuleSkipReason|null,
     *     match: CashTransactionAutoRuleMatchResult,
     *     plan: CashTransactionAutoRuleApplicationPlan|null
     * }>
     */
    public function previewRule(
        CashTransactionAutoRule $rule,
        iterable $transactions,
        int $limit,
    ): array {
        $rows = [];
        $activeRules = $this->ruleRepo->findActiveByCompany($rule->getCompany());

        foreach ($transactions as $transaction) {
            if (!$this->ruleMatchesTransaction($rule, $transaction)) {
                continue;
            }

            $skipReason = $this->getSkipReason($transaction);
            $match = null === $skipReason
                ? $this->resolveMatch($transaction, $activeRules)
                : new CashTransactionAutoRuleMatchResult(null);
            $rows[] = [
                'transaction' => $transaction,
                'skipReason' => $skipReason,
                'match' => $match,
                'plan' => null !== $match->rule
                    ? $this->createApplicationPlan($match->rule, $transaction)
                    : null,
            ];

            if (count($rows) >= $limit) {
                break;
            }
        }

        return $rows;
    }

    /** @param list<CashTransactionAutoRule> $rules */
    private function resolveMatch(CashTransaction $transaction, array $rules): CashTransactionAutoRuleMatchResult
    {
        $matchingRules = [];
        $matchingPriority = null;

        foreach ($rules as $rule) {
            if (null !== $matchingPriority && $rule->getPriority() < $matchingPriority) {
                break;
            }

            if ($this->ruleMatchesTransaction($rule, $transaction)) {
                $matchingPriority ??= $rule->getPriority();
                $matchingRules[] = $rule;
            }
        }

        if ([] === $matchingRules) {
            return new CashTransactionAutoRuleMatchResult(null);
        }

        $winner = $matchingRules[0];
        foreach (array_slice($matchingRules, 1) as $rule) {
            if ($this->getRuleEffectSignature($winner) !== $this->getRuleEffectSignature($rule)) {
                return new CashTransactionAutoRuleMatchResult(null, $matchingRules);
            }
        }

        return new CashTransactionAutoRuleMatchResult($winner);
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

    /** @return array{string, ?string, ?string, ?string} */
    private function getRuleEffectSignature(CashTransactionAutoRule $rule): array
    {
        return [
            $rule->getAction()->value,
            $rule->getCashflowCategory()?->getId(),
            $rule->getProjectDirection()?->getId(),
            $rule->getCounterparty()?->getId(),
        ];
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
        if ($resolvedMatch->rule !== $rule) {
            return null;
        }

        $plan = $this->createApplicationPlan($rule, $t);
        $this->applyPlan($plan, $t);

        return $plan;
    }

    public function createApplicationPlan(
        CashTransactionAutoRule $rule,
        CashTransaction $t,
    ): CashTransactionAutoRuleApplicationPlan {
        $changes = [];
        $category = $rule->getCashflowCategory(); // в сущности правила поле not-null
        $projectDirection = $rule->getProjectDirection();
        $counterparty = $rule->getCounterparty();
        $action = $rule->getAction();

        // Семантика:
        // FILL   — ставим категорию только если у транзакции она пуста или не распределена
        // UPDATE — перезаписываем всегда
        if (CashTransactionAutoRuleAction::FILL === $action) {
            $currentCategory = $t->getCashflowCategory();
            $isCategoryEmpty = null === $currentCategory || in_array(
                $currentCategory->getCode(),
                [CashflowCategory::CODE_UNALLOCATED, CashflowCategory::SYSTEM_UNALLOCATED],
                true,
            );
            if (null !== $category && $currentCategory?->getId() !== $category->getId() && $isCategoryEmpty) {
                $changes['cashflowCategory'] = [
                    'before' => $currentCategory?->getId(),
                    'after' => $category->getId(),
                ];
            }
            if (null === $t->getProjectDirection() && null !== $projectDirection) {
                $changes['projectDirection'] = [
                    'before' => null,
                    'after' => $projectDirection->getId(),
                ];
            }
            if (null === $t->getCounterparty() && null !== $counterparty) {
                $changes['counterparty'] = [
                    'before' => null,
                    'after' => $counterparty->getId(),
                ];
            }
        } else { // UPDATE
            if ($t->getCashflowCategory()?->getId() !== $category?->getId()) {
                $changes['cashflowCategory'] = [
                    'before' => $t->getCashflowCategory()?->getId(),
                    'after' => $category?->getId(),
                ];
            }
            if ($t->getProjectDirection()?->getId() !== $projectDirection?->getId()) {
                $changes['projectDirection'] = [
                    'before' => $t->getProjectDirection()?->getId(),
                    'after' => $projectDirection?->getId(),
                ];
            }
            if ($t->getCounterparty()?->getId() !== $counterparty?->getId()) {
                $changes['counterparty'] = [
                    'before' => $t->getCounterparty()?->getId(),
                    'after' => $counterparty?->getId(),
                ];
            }
        }

        return new CashTransactionAutoRuleApplicationPlan(
            $rule,
            $changes,
            $category,
            $projectDirection,
            $counterparty,
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
