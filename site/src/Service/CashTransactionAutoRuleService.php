<?php
namespace App\Service;

use App\Entity\CashTransaction;
use App\Entity\CashTransactionAutoRule;
use App\Enum\CashDirection;
use App\Enum\CashTransactionAutoRuleAction;
use App\Enum\CashTransactionAutoRuleConditionField;
use App\Enum\CashTransactionAutoRuleConditionOperator;
use App\Repository\CashTransactionAutoRuleRepository;
use Doctrine\ORM\EntityManagerInterface;

class CashTransactionAutoRuleService
{
    public function __construct(
        private CashTransactionAutoRuleRepository $ruleRepo,
        private EntityManagerInterface $em
    ) {}

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
            if ($opType->value !== 'ANY') {
                if ($opType->value === 'INFLOW'  && $t->getDirection() !== CashDirection::INFLOW)  { continue; }
                if ($opType->value === 'OUTFLOW' && $t->getDirection() !== CashDirection::OUTFLOW) { continue; }
            }

            // 2) Все условия (AND)
            $ok = true;
            foreach ($rule->getConditions() as $cond) {
                $field    = $cond->getField();
                $operator = $cond->getOperator();
                $value    = (string) ($cond->getValue() ?? '');
                $valueTo  = (string) ($cond->getValueTo() ?? '');

                switch ($field) {
                    case CashTransactionAutoRuleConditionField::COUNTERPARTY:
                        // точное совпадение по выбранному контрагенту
                        if ($t->getCounterparty() !== $cond->getCounterparty()) { $ok = false; }
                        break;

                    case CashTransactionAutoRuleConditionField::COUNTERPARTY_NAME:
                        $name = $t->getCounterparty()?->getName() ?? '';
                        if (!$this->containsNormalized($name, $value)) { $ok = false; }
                        break;

                    case CashTransactionAutoRuleConditionField::INN:
                        $inn = preg_replace('/\D+/', '', (string)($t->getCounterparty()?->getInn() ?? ''));
                        $val = preg_replace('/\D+/', '', $value);
                        if ($inn !== $val) { $ok = false; }
                        break;

                    case CashTransactionAutoRuleConditionField::DATE:
                        // EQUAL — в пределах суток; BETWEEN — включая границы
                        $d = $t->getOccurredAt();
                        if ($operator === CashTransactionAutoRuleConditionOperator::BETWEEN) {
                            $from = new \DateTimeImmutable($value.' 00:00:00');
                            $to   = new \DateTimeImmutable($valueTo.' 23:59:59');
                            if ($d < $from || $d > $to) { $ok = false; }
                        } else { // EQUAL
                            $from = new \DateTimeImmutable($value.' 00:00:00');
                            $to   = new \DateTimeImmutable($value.' 23:59:59');
                            if ($d < $from || $d > $to) { $ok = false; }
                        }
                        break;

                    case CashTransactionAutoRuleConditionField::AMOUNT:
                        // amount хранится строкой; сравним через bccomp при масштабе 2
                        $amt = $t->getAmount();
                        $cmp = fn(string $a, string $b) => \bccomp($a, $b, 2);
                        if ($operator === CashTransactionAutoRuleConditionOperator::BETWEEN) {
                            if (!($cmp($amt, (string)$value) >= 0 && $cmp($amt, (string)$valueTo) <= 0)) { $ok = false; }
                        } elseif ($operator === CashTransactionAutoRuleConditionOperator::GREATER_THAN) {
                            if (!($cmp($amt, (string)$value) > 0)) { $ok = false; }
                        } elseif ($operator === CashTransactionAutoRuleConditionOperator::LESS_THAN) {
                            if (!($cmp($amt, (string)$value) < 0)) { $ok = false; }
                        } else { // EQUAL
                            if (!($cmp($amt, (string)$value) === 0)) { $ok = false; }
                        }
                        break;

                    case CashTransactionAutoRuleConditionField::DESCRIPTION:
                        $desc = $t->getDescription() ?? '';
                        if (!$this->containsNormalized($desc, $value)) { $ok = false; }
                        break;
                }

                if (!$ok) { break; }
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
     * NB: в текущей модели правила меняют только Категорию ДДС.
     */
    public function applyRule(CashTransactionAutoRule $rule, CashTransaction $t): bool
    {
        $changed = false;

        // безопасность по компании
        if ($rule->getCompany() !== $t->getCompany()) {
            return false;
        }

        $category = $rule->getCashflowCategory(); // в сущности правила поле not-null
        $action   = $rule->getAction();

        // Семантика:
        // FILL   — ставим категорию только если у транзакции она пуста
        // UPDATE — перезаписываем всегда
        if ($action === CashTransactionAutoRuleAction::FILL) {
            if ($t->getCashflowCategory() === null) {
                $t->setCashflowCategory($category);
                $changed = true;
            }
        } else { // UPDATE
            if ($t->getCashflowCategory() !== $category) {
                $t->setCashflowCategory($category);
                $changed = true;
            }
        }

        if ($changed) {
            $this->em->flush(); // одна транзакция — можно сразу
        }

        return $changed;
    }

    private function containsNormalized(string $haystack, string $needle): bool
    {
        $norm = fn(string $s) => mb_strtolower(str_replace('ё','е',$s));
        $h = $norm($haystack);
        $n = $norm($needle);
        return $n !== '' && mb_strpos($h, $n) !== false;
    }
}
