<?php

namespace App\Cash\Service\Transaction;

use App\Cash\Application\DTO\CashTransactionAutoRuleMatchResult;
use App\Cash\Application\Service\AutoRuleDispatchGuard;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Entity\Transaction\CashTransactionAutoRule;
use App\Cash\Enum\Transaction\CashDirection;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleAction;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleConditionField;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleConditionOperator;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleSkipReason;
use App\Cash\Repository\Transaction\CashTransactionAutoRuleRepository;
use App\Util\StringNormalizer;
use Doctrine\ORM\EntityManagerInterface;

class CashTransactionAutoRuleService
{
    public function __construct(
        private CashTransactionAutoRuleRepository $ruleRepo,
        private EntityManagerInterface $em,
        private AutoRuleDispatchGuard $dispatchGuard,
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
     *     match: CashTransactionAutoRuleMatchResult
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
            $rows[] = [
                'transaction' => $transaction,
                'skipReason' => $skipReason,
                'match' => null === $skipReason
                    ? $this->resolveMatch($transaction, $activeRules)
                    : new CashTransactionAutoRuleMatchResult(null),
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
        if ($rule->getCompany() !== $transaction->getCompany()) {
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

    /**
     * Применить правило к одной транзакции.
     * Возвращает true, если были изменения; false — если изменений не было.
     * NB: в текущей модели правила меняют Категорию ДДС и Направление/Проект.
     */
    public function applyRule(CashTransactionAutoRule $rule, CashTransaction $t): bool
    {
        $changed = false;

        if (null !== $this->getSkipReason($t)) {
            return false;
        }

        if (!$rule->isActive()) {
            return false;
        }

        // безопасность по компании
        if ($rule->getCompany() !== $t->getCompany()) {
            return false;
        }

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
            if (null !== $category && $currentCategory !== $category && $isCategoryEmpty) {
                $t->setCashflowCategory($category);
                $changed = true;
            }
            if (null === $t->getProjectDirection() && null !== $projectDirection) {
                $t->setProjectDirection($projectDirection);
                $changed = true;
            }
            if (null === $t->getCounterparty() && null !== $counterparty) {
                $t->setCounterparty($counterparty);
                $changed = true;
            }
        } else { // UPDATE
            if ($t->getCashflowCategory() !== $category) {
                $t->setCashflowCategory($category);
                $changed = true;
            }
            if ($t->getProjectDirection() !== $projectDirection) {
                $t->setProjectDirection($projectDirection);
                $changed = true;
            }
            if ($t->getCounterparty() !== $counterparty) {
                $t->setCounterparty($counterparty);
                $changed = true;
            }
        }

        if ($changed) {
            $this->dispatchGuard->suppress(fn () => $this->em->flush());
        }

        return $changed;
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
