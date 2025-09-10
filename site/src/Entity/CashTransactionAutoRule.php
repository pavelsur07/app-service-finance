<?php

namespace App\Entity;

use App\Enum\CashTransactionAutoRuleAction;
use App\Enum\CashTransactionAutoRuleOperationType;
use App\Repository\CashTransactionAutoRuleRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Webmozart\Assert\Assert;

#[ORM\Entity(repositoryClass: CashTransactionAutoRuleRepository::class)]
#[ORM\Table(name: 'cash_transaction_auto_rule')]
#[ORM\Index(name: 'idx_ctar_company', columns: ['company_id'])]
#[ORM\Index(name: 'idx_ctar_category', columns: ['cashflow_category_id'])]
class CashTransactionAutoRule
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Company $company;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(enumType: CashTransactionAutoRuleAction::class)]
    private CashTransactionAutoRuleAction $action;

    #[ORM\Column(enumType: CashTransactionAutoRuleOperationType::class)]
    private CashTransactionAutoRuleOperationType $operationType;

    #[ORM\ManyToOne(targetEntity: CashflowCategory::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?CashflowCategory $cashflowCategory = null;

    /** @var Collection<int, CashTransactionAutoRuleCondition> */
    #[ORM\OneToMany(mappedBy: 'autoRule', targetEntity: CashTransactionAutoRuleCondition::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $conditions;

    public function __construct(
        string $id,
        Company $company,
        string $name,
        CashTransactionAutoRuleAction $action,
        CashTransactionAutoRuleOperationType $operationType,
        ?CashflowCategory $cashflowCategory = null
    ) {
        Assert::uuid($id);
        $this->id = $id;
        $this->company = $company;
        $this->name = $name;
        $this->action = $action;
        $this->operationType = $operationType;
        if ($cashflowCategory) {
            $this->cashflowCategory = $cashflowCategory;
        }
        $this->conditions = new ArrayCollection();
    }

    public function getId(): ?string { return $this->id; }
    public function getCompany(): Company { return $this->company; }
    public function setCompany(Company $company): self { $this->company = $company; return $this; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }
    public function getAction(): CashTransactionAutoRuleAction { return $this->action; }
    public function setAction(CashTransactionAutoRuleAction $action): self { $this->action = $action; return $this; }
    public function getOperationType(): CashTransactionAutoRuleOperationType { return $this->operationType; }
    public function setOperationType(CashTransactionAutoRuleOperationType $operationType): self { $this->operationType = $operationType; return $this; }
    public function getCashflowCategory(): ?CashflowCategory { return $this->cashflowCategory; }
    public function setCashflowCategory(CashflowCategory $cashflowCategory): self { $this->cashflowCategory = $cashflowCategory; return $this; }

    /** @return Collection<int, CashTransactionAutoRuleCondition> */
    public function getConditions(): Collection { return $this->conditions; }
    public function addCondition(CashTransactionAutoRuleCondition $condition): self
    {
        if (!$this->conditions->contains($condition)) {
            $this->conditions->add($condition);
            $condition->setAutoRule($this);
        }
        return $this;
    }
    public function removeCondition(CashTransactionAutoRuleCondition $condition): self
    {
        if ($this->conditions->removeElement($condition)) {
            if ($condition->getAutoRule() === $this) {
                $condition->setAutoRule(null);
            }
        }
        return $this;
    }
}
