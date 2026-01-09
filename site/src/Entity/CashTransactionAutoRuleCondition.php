<?php

namespace App\Entity;

use App\Cash\Repository\Transaction\CashTransactionAutoRuleConditionRepository;
use App\Enum\CashTransactionAutoRuleConditionField;
use App\Enum\CashTransactionAutoRuleConditionOperator;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;
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
}
