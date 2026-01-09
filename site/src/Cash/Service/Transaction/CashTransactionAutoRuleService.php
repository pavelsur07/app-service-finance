<?php

namespace App\Cash\Service\Transaction;

use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Entity\Transaction\CashTransactionAutoRule;
use App\Cash\Repository\Transaction\CashTransactionAutoRuleRepository;
use App\Enum\CashDirection;
use App\Enum\CashTransactionAutoRuleAction;
use App\Enum\CashTransactionAutoRuleConditionField;
use App\Enum\CashTransactionAutoRuleConditionOperator;
use App\Util\StringNormalizer;
use Doctrine\ORM\EntityManagerInterface;

class CashTransactionAutoRuleService
{
    public function __construct(
        private CashTransactionAutoRuleRepository $ruleRepo,
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * Найти ПЕРВОЕ подходящее правило для транзакции (в пределах компании транзакции).
     * Возвращает null, если ни одно правило не подошло.
     */
    public function findMatchingRule(CashTransaction $t): ?CashTransactionAutoRule
    {
        $company = $t->getCompany();
        $rules = $this->ruleRepo->findByCompany($company); // уже есть в репозитории

        foreach ($rules as $rule) {
            // 1) Тип операции
            $opType = $rule->getOperationType();
            if ('ANY' !== $opType->value) {
                if ('INFLOW' === $opType->value && CashDirection::INFLOW !== $t->getDirection()) {
                    continue;
                }
                if ('OUTFLOW' === $opType->value && CashDirection::OUTFLOW !== $t->getDirection()) {
                    continue;
                }
            }

            // 2) Все условия (AND)
            $ok = true;
            foreach ($rule->getConditions() as $cond) {
                $field = $cond->getField();
                $operator = $cond->getOperator();
                $value = (string) ($cond->getValue() ?? '');
                $valueTo = (string) ($cond->getValueTo() ?? '');

                switch ($field) {
                    case CashTransactionAutoRuleConditionField::COUNTERPARTY:
                        // точное совпадение по выбранному контрагенту
                        if ($t->getCounterparty() !== $cond->getCounterparty()) {
                            $ok = false;
                        }
                        break;

                    case CashTransactionAutoRuleConditionField::COUNTERPARTY_NAME:
                        $name = $t->getCounterparty()?->getName() ?? '';
                        if (!StringNormalizer::contains($name, $value)) {
                            $ok = false;
                        }
                        break;

                    case CashTransactionAutoRuleConditionField::INN:
                        $inn = preg_replace('/\D+/', '', (string) ($t->getCounterparty()?->getInn() ?? ''));
                        $val = preg_replace('/\D+/', '', $value);
                        if ($inn !== $val) {
                            $ok = false;
                        }
                        break;

                    case CashTransactionAutoRuleConditionField::DATE:
                        // EQUAL — в пределах суток; BETWEEN — включая границы
                        $d = $t->getOccurredAt();
                        if (CashTransactionAutoRuleConditionOperator::BETWEEN === $operator) {
                            $from = new \DateTimeImmutable($value.' 00:00:00');
                            $to = new \DateTimeImmutable($valueTo.' 23:59:59');
                            if ($d < $from || $d > $to) {
                                $ok = false;
                            }
                        } else { // EQUAL
                            $from = new \DateTimeImmutable($value.' 00:00:00');
                            $to = new \DateTimeImmutable($value.' 23:59:59');
                            if ($d < $from || $d > $to) {
                                $ok = false;
                            }
                        }
                        break;

                    case CashTransactionAutoRuleConditionField::AMOUNT:
                        // amount хранится строкой; сравним через bccomp при масштабе 2
                        $amt = $t->getAmount();
                        $cmp = fn (string $a, string $b) => \bccomp($a, $b, 2);
                        if (CashTransactionAutoRuleConditionOperator::BETWEEN === $operator) {
                            if (!($cmp($amt, (string) $value) >= 0 && $cmp($amt, (string) $valueTo) <= 0)) {
                                $ok = false;
                            }
                        } elseif (CashTransactionAutoRuleConditionOperator::GREATER_THAN === $operator) {
                            if (!($cmp($amt, (string) $value) > 0)) {
                                $ok = false;
                            }
                        } elseif (CashTransactionAutoRuleConditionOperator::LESS_THAN === $operator) {
                            if (!($cmp($amt, (string) $value) < 0)) {
                                $ok = false;
                            }
                        } else { // EQUAL
                            if (!(0 === $cmp($amt, (string) $value))) {
                                $ok = false;
                            }
                        }
                        break;

                    case CashTransactionAutoRuleConditionField::DESCRIPTION:
                        $desc = $t->getDescription() ?? '';
                        if (!StringNormalizer::contains($desc, $value)) {
                            $ok = false;
                        }
                        break;
                }

                if (!$ok) {
                    break;
                }
            }

            if ($ok) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * Применить правило к одной транзакции.
     * Возвращает true, если были изменения; false — если изменений не было.
     * NB: в текущей модели правила меняют Категорию ДДС и Направление/Проект.
     */
    public function applyRule(CashTransactionAutoRule $rule, CashTransaction $t): bool
    {
        $changed = false;

        // безопасность по компании
        if ($rule->getCompany() !== $t->getCompany()) {
            return false;
        }

        $category = $rule->getCashflowCategory(); // в сущности правила поле not-null
        $projectDirection = $rule->getProjectDirection();
        $counterparty = $rule->getCounterparty();
        $action = $rule->getAction();

        // Семантика:
        // FILL   — ставим категорию только если у транзакции она пуста
        // UPDATE — перезаписываем всегда
        if (CashTransactionAutoRuleAction::FILL === $action) {
            if (null === $t->getCashflowCategory() && null !== $category) {
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
            $this->em->flush(); // одна транзакция — можно сразу
        }

        return $changed;
    }
}
