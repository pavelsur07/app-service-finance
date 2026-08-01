<?php

declare(strict_types=1);

namespace App\Cash\Application\Service;

use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Entity\Transaction\CashTransactionAutoRule;
use App\Cash\Entity\Transaction\CashTransactionAutoRuleCondition;
use App\Cash\Enum\Transaction\CashDirection;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleConditionField;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleConditionOperator;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleOperationType;

/**
 * Скелет автоправила из операции разбора: направление и статья берутся как есть,
 * условие — контрагент, если он распознан.
 *
 * Описание намеренно не подставляется в условие целиком: правило на «Оплата по
 * счету №1423 от 12.03.2026» совпадёт ровно с одной операцией и больше никогда.
 * Устойчивый фрагмент выбирает пользователь, глядя на карточку операции.
 */
final readonly class CashTransactionAutoRulePrefiller
{
    /**
     * @param list<CashflowCategory> $availableCategories статьи, доступные в форме правила
     */
    public function prefill(
        CashTransactionAutoRule $rule,
        CashTransaction $transaction,
        array $availableCategories,
    ): void {
        $rule->setOperationType(CashDirection::INFLOW === $transaction->getDirection()
            ? CashTransactionAutoRuleOperationType::INFLOW
            : CashTransactionAutoRuleOperationType::OUTFLOW);

        // Заглушка «не распределено» как цель правила бесполезна, а недоступная в
        // форме статья молча отрисовалась бы пустым селектом.
        // Категория берётся из строк: у транзакции с разбивкой единственной категории нет,
        // и предзаполнять правило одной из нескольких было бы догадкой.
        $splits = $transaction->getSplits();
        $category = 1 === $splits->count() ? $splits->first()->getCashflowCategory() : null;
        if (null === $category
            || CashflowCategory::CODE_UNALLOCATED === $category->getSystemCode()
            || !in_array($category, $availableCategories, true)
        ) {
            $category = null;
        } else {
            $rule->setCashflowCategory($category);
        }

        $counterparty = $transaction->getCounterparty();
        $rule->setName(implode(' → ', array_filter([$counterparty?->getName(), $category?->getName()])));

        $rule->addCondition(null !== $counterparty
            ? new CashTransactionAutoRuleCondition(
                field: CashTransactionAutoRuleConditionField::COUNTERPARTY,
                operator: CashTransactionAutoRuleConditionOperator::EQUAL,
                counterparty: $counterparty,
            )
            : new CashTransactionAutoRuleCondition(
                field: CashTransactionAutoRuleConditionField::DESCRIPTION,
                operator: CashTransactionAutoRuleConditionOperator::CONTAINS,
            ));
    }
}
