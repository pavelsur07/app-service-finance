<?php

declare(strict_types=1);

namespace App\Cash\Entity\Transaction;

use App\Cash\Enum\Transaction\CashTransactionAutoRuleAction;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleOperationType;
use App\Cash\Repository\Transaction\CashTransactionAutoRuleRepository;
use App\Company\Entity\Company;
use App\Company\Entity\Counterparty;
use App\Company\Entity\ProjectDirection;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as AssertConstraint;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Webmozart\Assert\Assert;

#[ORM\Entity(repositoryClass: CashTransactionAutoRuleRepository::class)]
#[ORM\Table(name: 'cash_transaction_auto_rule')]
#[ORM\Index(name: 'idx_ctar_company', columns: ['company_id'])]
#[ORM\Index(name: 'idx_ctar_category', columns: ['cashflow_category_id'])]
#[ORM\Index(name: 'idx_ctar_counterparty', columns: ['counterparty_id'])]
#[ORM\Index(name: 'idx_ctar_company_active_priority', columns: ['company_id', 'is_active', 'priority'])]
class CashTransactionAutoRule
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Company $company;

    #[ORM\Column(length: 255)]
    #[AssertConstraint\NotBlank(message: 'Укажите название автоправила.')]
    #[AssertConstraint\Length(max: 255, maxMessage: 'Название не должно быть длиннее {{ limit }} символов.')]
    private string $name;

    #[ORM\Column(enumType: CashTransactionAutoRuleAction::class)]
    private CashTransactionAutoRuleAction $action;

    #[ORM\Column(enumType: CashTransactionAutoRuleOperationType::class)]
    private CashTransactionAutoRuleOperationType $operationType;

    #[ORM\Column(options: ['default' => 100])]
    private int $priority = 100;

    #[ORM\Column(options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Version]
    #[ORM\Column(type: 'integer', options: ['default' => 1])]
    private int $revision = 1;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $disabledAt = null;

    #[ORM\Column(type: 'guid', nullable: true)]
    private ?string $createdByUserId = null;

    #[ORM\Column(type: 'guid', nullable: true)]
    private ?string $updatedByUserId = null;

    #[ORM\Column(type: 'guid', nullable: true)]
    private ?string $disabledByUserId = null;

    #[ORM\ManyToOne(targetEntity: Counterparty::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Counterparty $counterparty = null;

    #[ORM\ManyToOne(targetEntity: CashflowCategory::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    #[AssertConstraint\NotNull(message: 'Выберите статью ДДС.')]
    private ?CashflowCategory $cashflowCategory = null;

    #[ORM\ManyToOne(targetEntity: ProjectDirection::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?ProjectDirection $projectDirection = null;

    #[ORM\Column(type: 'guid', nullable: true)]
    private ?string $responsibilityCenterId = null;

    /** @var Collection<int, CashTransactionAutoRuleCondition> */
    #[ORM\OneToMany(mappedBy: 'autoRule', targetEntity: CashTransactionAutoRuleCondition::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[AssertConstraint\Count(min: 1, minMessage: 'Добавьте хотя бы одно условие.')]
    #[AssertConstraint\Valid]
    private Collection $conditions;

    public function __construct(
        string $id,
        Company $company,
        string $name,
        CashTransactionAutoRuleAction $action,
        CashTransactionAutoRuleOperationType $operationType,
        ?CashflowCategory $cashflowCategory = null,
        ?Counterparty $counterparty = null,
        ?string $createdByUserId = null,
    ) {
        Assert::uuid($id);
        if (null !== $createdByUserId) {
            Assert::uuid($createdByUserId);
        }

        $now = new \DateTimeImmutable();
        $this->id = $id;
        $this->company = $company;
        $this->name = $name;
        $this->action = $action;
        $this->operationType = $operationType;
        if ($cashflowCategory) {
            $this->cashflowCategory = $cashflowCategory;
        }
        if ($counterparty) {
            $this->counterparty = $counterparty;
        }
        $this->createdAt = $now;
        $this->updatedAt = $now;
        $this->createdByUserId = $createdByUserId;
        $this->updatedByUserId = $createdByUserId;
        $this->conditions = new ArrayCollection();
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function setCompany(Company $company): self
    {
        Assert::same($company->getId(), $this->company->getId(), 'Компания автоправила не может быть изменена.');

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getAction(): CashTransactionAutoRuleAction
    {
        return $this->action;
    }

    public function setAction(CashTransactionAutoRuleAction $action): self
    {
        $this->action = $action;

        return $this;
    }

    public function getOperationType(): CashTransactionAutoRuleOperationType
    {
        return $this->operationType;
    }

    public function setOperationType(CashTransactionAutoRuleOperationType $operationType): self
    {
        $this->operationType = $operationType;

        return $this;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function setPriority(int $priority): self
    {
        $this->priority = $priority;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): self
    {
        if ($isActive) {
            $this->enable();
        } else {
            $this->disable();
        }

        return $this;
    }

    public function enable(?string $actorUserId = null): bool
    {
        if ($this->isActive) {
            return false;
        }

        $this->assertActorUserId($actorUserId);
        $now = new \DateTimeImmutable();
        $this->isActive = true;
        $this->disabledAt = null;
        $this->disabledByUserId = null;
        $this->recordUpdate($actorUserId, $now);

        return true;
    }

    public function disable(?string $actorUserId = null): bool
    {
        if (!$this->isActive) {
            return false;
        }

        $this->assertActorUserId($actorUserId);
        $now = new \DateTimeImmutable();
        $this->isActive = false;
        $this->disabledAt = $now;
        $this->disabledByUserId = $actorUserId;
        $this->recordUpdate($actorUserId, $now);

        return true;
    }

    public function recordUpdate(?string $actorUserId = null, ?\DateTimeImmutable $updatedAt = null): self
    {
        $this->assertActorUserId($actorUserId);
        $this->updatedAt = $updatedAt ?? new \DateTimeImmutable();
        $this->updatedByUserId = $actorUserId;

        return $this;
    }

    public function getRevision(): int
    {
        return $this->revision;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getDisabledAt(): ?\DateTimeImmutable
    {
        return $this->disabledAt;
    }

    public function getCreatedByUserId(): ?string
    {
        return $this->createdByUserId;
    }

    public function getUpdatedByUserId(): ?string
    {
        return $this->updatedByUserId;
    }

    public function getDisabledByUserId(): ?string
    {
        return $this->disabledByUserId;
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

    public function getCashflowCategory(): ?CashflowCategory
    {
        return $this->cashflowCategory;
    }

    public function setCashflowCategory(CashflowCategory $cashflowCategory): self
    {
        $this->cashflowCategory = $cashflowCategory;

        return $this;
    }

    public function getProjectDirection(): ?ProjectDirection
    {
        return $this->projectDirection;
    }

    public function setProjectDirection(?ProjectDirection $projectDirection): self
    {
        $this->projectDirection = $projectDirection;

        return $this;
    }

    public function getResponsibilityCenterId(): ?string
    {
        return $this->responsibilityCenterId;
    }

    public function setResponsibilityCenterId(?string $responsibilityCenterId): self
    {
        if (null !== $responsibilityCenterId) {
            Assert::uuid($responsibilityCenterId);
        }

        $this->responsibilityCenterId = $responsibilityCenterId;

        return $this;
    }

    /** @return Collection<int, CashTransactionAutoRuleCondition> */
    public function getConditions(): Collection
    {
        return $this->conditions;
    }

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

    /** Detached form data. Symfony may change existing condition objects during handleRequest(). */
    public function editableDraft(): self
    {
        $draft = clone $this;
        $draft->conditions = new ArrayCollection();
        foreach ($this->conditions as $condition) {
            $draft->addCondition(clone $condition);
        }

        return $draft;
    }

    public function definitionHash(): string
    {
        return hash('sha256', json_encode([
            $this->name, $this->priority, $this->action->value, $this->operationType->value,
            $this->cashflowCategory?->getId(), $this->projectDirection?->getId(),
            $this->responsibilityCenterId, $this->counterparty?->getId(), $this->conditionDefinitions(),
        ], \JSON_THROW_ON_ERROR));
    }

    public function approveDraft(self $draft, string $actorUserId): void
    {
        Assert::same($this->id, $draft->id);
        Assert::same($this->company->getId(), $draft->company->getId());
        if ($this->definitionHash() !== $draft->definitionHash()) {
            $this->name = $draft->name;
            $this->priority = $draft->priority;
            $this->action = $draft->action;
            $this->operationType = $draft->operationType;
            $this->cashflowCategory = $draft->cashflowCategory;
            $this->projectDirection = $draft->projectDirection;
            $this->responsibilityCenterId = $draft->responsibilityCenterId;
            $this->counterparty = $draft->counterparty;
            if ($this->conditionDefinitions() !== $draft->conditionDefinitions()) {
                foreach ($this->conditions->toArray() as $condition) {
                    $this->removeCondition($condition);
                }
                foreach ($draft->conditions as $condition) {
                    $this->addCondition(clone $condition);
                }
            }
            $this->recordUpdate($actorUserId);
        }
        $this->enable($actorUserId);
    }

    /** @return list<string> */
    private function conditionDefinitions(): array
    {
        $conditions = [];
        foreach ($this->conditions as $condition) {
            $conditions[] = json_encode([
                $condition->getField()->value, $condition->getOperator()->value,
                $condition->getCounterparty()?->getId(), $condition->getMoneyAccount()?->getId(),
                $condition->getValue(), $condition->getValueTo(),
            ], \JSON_THROW_ON_ERROR);
        }
        // Условия объединены через И; порядок строк в коллекции Doctrine не задан.
        sort($conditions, \SORT_STRING);

        return $conditions;
    }

    #[AssertConstraint\Callback]
    public function validateCompanyScope(ExecutionContextInterface $context): void
    {
        $associations = [
            'cashflowCategory' => $this->cashflowCategory,
            'projectDirection' => $this->projectDirection,
            'counterparty' => $this->counterparty,
        ];

        foreach ($associations as $path => $association) {
            if (null !== $association && $association->getCompany()->getId() !== $this->company->getId()) {
                $context->buildViolation('Выбранное значение должно принадлежать компании автоправила.')
                    ->atPath($path)
                    ->addViolation();
            }
        }
    }

    private function assertActorUserId(?string $actorUserId): void
    {
        if (null !== $actorUserId) {
            Assert::uuid($actorUserId);
        }
    }
}
