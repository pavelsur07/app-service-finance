<?php

namespace App\Cash\Entity\Transaction;

use App\Cash\Enum\Transaction\CashTransactionAutoRuleConditionField;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleConditionOperator;
use App\Cash\Repository\Transaction\CashTransactionAutoRuleConditionRepository;
use App\Company\Entity\Counterparty;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Validator\Constraints as AssertConstraint;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Webmozart\Assert\Assert;

#[ORM\Entity(repositoryClass: CashTransactionAutoRuleConditionRepository::class)]
#[ORM\Table(name: 'cash_transaction_auto_rule_condition')]
#[ORM\Index(name: 'idx_ctarc_rule', columns: ['auto_rule_id'])]
class CashTransactionAutoRuleCondition
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: CashTransactionAutoRule::class, inversedBy: 'conditions')]
    #[ORM\JoinColumn(name: 'auto_rule_id', nullable: false, onDelete: 'CASCADE')]
    private ?CashTransactionAutoRule $autoRule = null;

    #[ORM\Column(enumType: CashTransactionAutoRuleConditionField::class)]
    private CashTransactionAutoRuleConditionField $field;

    #[ORM\Column(enumType: CashTransactionAutoRuleConditionOperator::class)]
    private CashTransactionAutoRuleConditionOperator $operator;

    #[ORM\ManyToOne(targetEntity: Counterparty::class)]
    private ?Counterparty $counterparty = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $value = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $valueTo = null;

    public function __construct(
        ?string $id = null,
        ?CashTransactionAutoRule $autoRule = null,
        ?CashTransactionAutoRuleConditionField $field = null,
        ?CashTransactionAutoRuleConditionOperator $operator = null,
        ?string $value = null,
        ?string $valueTo = null,
        ?Counterparty $counterparty = null,
    ) {
        $id = $id ?? Uuid::uuid4()->toString();
        Assert::uuid($id);
        $this->id = $id;
        $this->autoRule = $autoRule;
        if ($field) {
            $this->field = $field;
        }
        if ($operator) {
            $this->operator = $operator;
        }
        $this->value = $value;
        $this->valueTo = $valueTo;
        $this->counterparty = $counterparty;
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getAutoRule(): ?CashTransactionAutoRule
    {
        return $this->autoRule;
    }

    public function setAutoRule(?CashTransactionAutoRule $rule): self
    {
        $this->autoRule = $rule;

        return $this;
    }

    public function getField(): CashTransactionAutoRuleConditionField
    {
        return $this->field;
    }

    public function setField(CashTransactionAutoRuleConditionField $field): self
    {
        $this->field = $field;

        return $this;
    }

    public function getOperator(): CashTransactionAutoRuleConditionOperator
    {
        return $this->operator;
    }

    public function setOperator(CashTransactionAutoRuleConditionOperator $operator): self
    {
        $this->operator = $operator;

        return $this;
    }

    public function getCounterparty(): ?Counterparty
    {
        return $this->counterparty;
    }

    public function setCounterparty(?Counterparty $counterparty): self
    {
        $this->counterparty = $counterparty;

        return $this;
    }

    public function getValue(): ?string
    {
        return $this->value;
    }

    public function setValue(?string $value): self
    {
        $this->value = $value;

        return $this;
    }

    public function getValueTo(): ?string
    {
        return $this->valueTo;
    }

    public function setValueTo(?string $valueTo): self
    {
        $this->valueTo = $valueTo;

        return $this;
    }

    #[AssertConstraint\Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        if (!isset($this->field)) {
            $context->buildViolation('Выберите поле операции.')
                ->atPath('field')
                ->addViolation();
        }

        if (!isset($this->operator)) {
            $context->buildViolation('Выберите оператор.')
                ->atPath('operator')
                ->addViolation();
        }

        if (!isset($this->field, $this->operator)) {
            return;
        }

        $allowedOperators = match ($this->field) {
            CashTransactionAutoRuleConditionField::COUNTERPARTY => [CashTransactionAutoRuleConditionOperator::EQUAL],
            CashTransactionAutoRuleConditionField::COUNTERPARTY_NAME,
            CashTransactionAutoRuleConditionField::INN,
            CashTransactionAutoRuleConditionField::DESCRIPTION => [CashTransactionAutoRuleConditionOperator::CONTAINS],
            CashTransactionAutoRuleConditionField::DATE => [
                CashTransactionAutoRuleConditionOperator::EQUAL,
                CashTransactionAutoRuleConditionOperator::BETWEEN,
            ],
            CashTransactionAutoRuleConditionField::AMOUNT => [
                CashTransactionAutoRuleConditionOperator::EQUAL,
                CashTransactionAutoRuleConditionOperator::GREATER_THAN,
                CashTransactionAutoRuleConditionOperator::LESS_THAN,
                CashTransactionAutoRuleConditionOperator::BETWEEN,
            ],
        };

        if (!in_array($this->operator, $allowedOperators, true)) {
            $context->buildViolation('Выбранный оператор недоступен для этого поля.')
                ->atPath('operator')
                ->addViolation();

            return;
        }

        if (CashTransactionAutoRuleConditionField::COUNTERPARTY === $this->field) {
            if (null === $this->counterparty) {
                $context->buildViolation('Выберите контрагента.')
                    ->atPath('counterparty')
                    ->addViolation();
            }

            return;
        }

        $value = trim((string) $this->value);
        if ('' === $value) {
            $context->buildViolation('Укажите значение условия.')
                ->atPath('value')
                ->addViolation();

            return;
        }

        if (CashTransactionAutoRuleConditionField::INN === $this->field
            && 1 !== preg_match('/^(?:\d{10}|\d{12})$/D', $value)) {
            $context->buildViolation('ИНН должен содержать 10 или 12 цифр.')
                ->atPath('value')
                ->addViolation();
        }

        if (CashTransactionAutoRuleConditionField::DATE === $this->field && !$this->isValidDate($value)) {
            $context->buildViolation('Укажите корректную дату.')
                ->atPath('value')
                ->addViolation();
        }

        if (CashTransactionAutoRuleConditionField::AMOUNT === $this->field && !$this->isValidAmount($value)) {
            $context->buildViolation('Укажите сумму в формате 1000,00.')
                ->atPath('value')
                ->addViolation();
        }

        if (CashTransactionAutoRuleConditionOperator::BETWEEN !== $this->operator) {
            return;
        }

        $valueTo = trim((string) $this->valueTo);
        if ('' === $valueTo) {
            $context->buildViolation('Укажите верхнюю границу диапазона.')
                ->atPath('valueTo')
                ->addViolation();

            return;
        }

        if (CashTransactionAutoRuleConditionField::DATE === $this->field) {
            if (!$this->isValidDate($valueTo)) {
                $context->buildViolation('Укажите корректную дату.')
                    ->atPath('valueTo')
                    ->addViolation();
            } elseif ($this->isValidDate($value) && $value > $valueTo) {
                $context->buildViolation('Начало диапазона не может быть позже окончания.')
                    ->atPath('valueTo')
                    ->addViolation();
            }
        }

        if (CashTransactionAutoRuleConditionField::AMOUNT === $this->field) {
            if (!$this->isValidAmount($valueTo)) {
                $context->buildViolation('Укажите сумму в формате 1000,00.')
                    ->atPath('valueTo')
                    ->addViolation();
            } elseif ($this->isValidAmount($value) && bccomp($this->normalizeAmount($value), $this->normalizeAmount($valueTo), 2) > 0) {
                $context->buildViolation('Начало диапазона не может быть больше окончания.')
                    ->atPath('valueTo')
                    ->addViolation();
            }
        }
    }

    private function isValidDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return false !== $date && $date->format('Y-m-d') === $value;
    }

    private function isValidAmount(string $value): bool
    {
        return 1 === preg_match('/^-?\d+(?:[.,]\d{1,2})?$/D', $value);
    }

    private function normalizeAmount(string $value): string
    {
        return str_replace(',', '.', $value);
    }
}
