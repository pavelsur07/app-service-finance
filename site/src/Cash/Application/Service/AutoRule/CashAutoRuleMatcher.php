<?php

declare(strict_types=1);

namespace App\Cash\Application\Service\AutoRule;

use App\Cash\Application\DTO\CashTransactionAutoRuleMatchResult;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Entity\Transaction\CashTransactionAutoRule;
use App\Cash\Entity\Transaction\CashTransactionAutoRuleCondition;
use App\Cash\Enum\Transaction\CashDirection;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleConditionField;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleConditionOperator;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleOperationType;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleSkipReason;
use App\Company\Entity\Company;
use App\Util\StringNormalizer;

final readonly class CashAutoRuleMatcher
{
    /** @param list<CashTransactionAutoRule> $rules */
    public function match(CashTransaction $transaction, array $rules): CashTransactionAutoRuleMatchResult
    {
        if (null !== $this->getSkipReason($transaction)) {
            return new CashTransactionAutoRuleMatchResult(null);
        }

        $matchingRules = array_values(array_filter(
            $rules,
            fn (CashTransactionAutoRule $rule): bool => $this->matches($rule, $transaction),
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
        foreach (['cashflowCategory', 'projectDirection', 'responsibilityCenterId', 'counterparty'] as $field) {
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
            $winners['cashflowCategory']
                ?? $winners['projectDirection']
                ?? $winners['responsibilityCenterId']
                ?? $winners['counterparty']
                ?? null,
            array_values($conflictingRules),
            $matchingRules,
            $winners,
            $conflicts,
        );
    }

    public function matches(CashTransactionAutoRule $rule, CashTransaction $transaction): bool
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
            $exactValue = trim($value);

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
                CashTransactionAutoRuleConditionField::CURRENCY => $transaction->getCurrency() === $exactValue,
                CashTransactionAutoRuleConditionField::IMPORT_SOURCE => CashTransactionAutoRuleCondition::MISSING_IMPORT_SOURCE_VALUE === $exactValue
                    ? null === $transaction->getImportSource()
                    : $transaction->getImportSource() === $exactValue,
                CashTransactionAutoRuleConditionField::IS_TRANSFER => $transaction->isTransfer() === ('true' === $exactValue),
                CashTransactionAutoRuleConditionField::DOCUMENT_TYPE => $this->normalizedExactText($transaction->getDocType())
                    === $this->normalizedExactText($value),
                CashTransactionAutoRuleConditionField::MONEY_ACCOUNT => $transaction->getMoneyAccount()->getId()
                    === $condition->getMoneyAccount()?->getId(),
            };

            if (!$matches) {
                return false;
            }
        }

        return true;
    }

    private function normalizedExactText(?string $value): string
    {
        return mb_strtolower(trim((string) $value), 'UTF-8');
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

    /** @param 'cashflowCategory'|'projectDirection'|'responsibilityCenterId'|'counterparty' $field */
    private function getTargetId(CashTransactionAutoRule $rule, string $field): ?string
    {
        return match ($field) {
            'cashflowCategory' => $rule->getCashflowCategory()?->getId(),
            'projectDirection' => $rule->getProjectDirection()?->getId(),
            'responsibilityCenterId' => $rule->getResponsibilityCenterId(),
            'counterparty' => $rule->getCounterparty()?->getId(),
        };
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

        // Ручная разбивка по статьям — осознанное решение человека, и правило не вправе
        // его переписать. Проверка стоит здесь, а не в синхронизаторе строк: там ранний
        // выход оставил бы колонку и строки рассинхронизированными, потому что колонку
        // правило успело бы поменять до него.
        if ($transaction->getSplits()->count() > 1) {
            return CashTransactionAutoRuleSkipReason::MANUAL_SPLIT;
        }

        return null;
    }
}
