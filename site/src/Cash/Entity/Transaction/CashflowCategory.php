<?php

namespace App\Cash\Entity\Transaction;

use App\Cash\Enum\PaymentPlan\PaymentPlanType;
use App\Cash\Enum\Transaction\CashflowCategoryStatus;
use App\Cash\Enum\Transaction\CashflowFlowKind;
use App\Cash\Repository\Transaction\CashflowCategoryRepository;
use App\Company\Entity\Company;
use App\Finance\Entity\PLCategory;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Webmozart\Assert\Assert;

#[ORM\Entity(repositoryClass: CashflowCategoryRepository::class)]
#[ORM\Table(name: '`cashflow_categories`')]
#[ORM\UniqueConstraint(name: 'uniq_cashflow_category_company_code', columns: ['company_id', 'system_code'])]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['company', 'systemCode'], message: 'Код уже используется в этой компании.')]
class CashflowCategory
{
    public const SYSTEM_UNALLOCATED = 'UNALLOCATED';
    public const CODE_OPERATING = 'CF_OP';
    public const CODE_FINANCING = 'CF_FIN';
    public const CODE_INVESTING = 'CF_INV';
    public const CODE_TECHNICAL = 'CF_TECH';
    public const CODE_TECHNICAL_IN = 'CF_TECH_IN';
    public const CODE_TECHNICAL_OUT = 'CF_TECH_OUT';
    public const CODE_UNALLOCATED = 'CF_UNALLOC';

    private const SYSTEM_CODES = [
        self::CODE_OPERATING,
        self::CODE_FINANCING,
        self::CODE_INVESTING,
        self::CODE_TECHNICAL,
        self::CODE_TECHNICAL_IN,
        self::CODE_TECHNICAL_OUT,
        self::CODE_UNALLOCATED,
    ];

    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private ?string $id = null;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(enumType: CashflowCategoryStatus::class)]
    private CashflowCategoryStatus $status;

    #[ORM\Column(type: 'integer')]
    private int $sort = 0;

    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'children')]
    private ?self $parent = null;

    #[ORM\OneToMany(targetEntity: self::class, mappedBy: 'parent')]
    #[ORM\OrderBy(['sort' => 'ASC'])]
    private Collection $children;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Company $company;

    #[ORM\Column(enumType: PaymentPlanType::class, nullable: true)]
    private ?PaymentPlanType $operationType = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $allowPlDocument = false;

    #[ORM\ManyToOne(targetEntity: PLCategory::class)]
    private ?PLCategory $plCategory = null;

    #[ORM\Column(type: 'string', length: 32, nullable: true)]
    private ?string $systemCode = null;

    #[ORM\Column(enumType: CashflowFlowKind::class)]
    private CashflowFlowKind $flowKind;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isSystem = false;

    public function __construct(string $id, Company $company)
    {
        Assert::uuid($id);
        $this->id = $id;
        $this->company = $company;
        $this->status = CashflowCategoryStatus::ACTIVE;
        $this->flowKind = CashflowFlowKind::OPERATING;
        $this->children = new ArrayCollection();
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        if ($this->isSystem && isset($this->name) && $this->name !== $name) {
            throw new \DomainException('Системную категорию нельзя переименовать.');
        }

        $this->name = $name;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function getStatus(): CashflowCategoryStatus
    {
        return $this->status;
    }

    public function setStatus(CashflowCategoryStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getSort(): int
    {
        return $this->sort;
    }

    public function setSort(int $sort): self
    {
        if ($this->isSystem && $this->sort !== $sort) {
            throw new \DomainException('Системную категорию нельзя перемещать.');
        }

        $this->sort = $sort;

        return $this;
    }

    public function getParent(): ?self
    {
        return $this->parent;
    }

    public function setParent(?self $parent): self
    {
        if ($this->parent === $parent) {
            return $this;
        }

        if ($this->isSystem) {
            throw new \DomainException('Системную категорию нельзя перемещать.');
        }

        if ($parent === $this || (null !== $parent && $parent->isDescendantOf($this))) {
            throw new \DomainException('Нельзя выбрать родителем текущую категорию или её потомка.');
        }

        $this->parent?->children->removeElement($this);
        $this->parent = $parent;

        if (null !== $parent && !$parent->children->contains($this)) {
            $parent->children->add($this);
        }

        return $this;
    }

    public function isRoot(): bool
    {
        return null === $this->parent;
    }

    public function getChildren(): Collection
    {
        return $this->children;
    }

    public function isDescendantOf(self $category): bool
    {
        $parent = $this->parent;
        $visited = [];

        while (null !== $parent) {
            $objectId = spl_object_id($parent);
            if (isset($visited[$objectId])) {
                throw new \DomainException('В дереве категорий обнаружен цикл.');
            }
            $visited[$objectId] = true;

            if ($parent === $category) {
                return true;
            }

            $parent = $parent->parent;
        }

        return false;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function setCompany(Company $company): self
    {
        $this->company = $company;

        return $this;
    }

    public function getOperationType(): ?PaymentPlanType
    {
        return $this->operationType;
    }

    public function setOperationType(?PaymentPlanType $operationType): self
    {
        $this->operationType = $operationType;

        return $this;
    }

    public function isAllowPlDocument(): bool
    {
        return $this->allowPlDocument;
    }

    public function setAllowPlDocument(bool $allowPlDocument): self
    {
        $this->allowPlDocument = $allowPlDocument;

        return $this;
    }

    public function getPlCategory(): ?PLCategory
    {
        return $this->plCategory;
    }

    public function setPlCategory(?PLCategory $plCategory): self
    {
        $this->plCategory = $plCategory;

        return $this;
    }

    public function getSystemCode(): ?string
    {
        return $this->getCode();
    }

    public function setSystemCode(?string $code): self
    {
        return $this->setCode($code);
    }

    public function getCode(): ?string
    {
        return $this->systemCode;
    }

    public function setCode(?string $code): self
    {
        $code = self::normalizeCode($code);

        if (!$this->isSystem && self::isSystemCode($code)) {
            throw new \DomainException('Этот код зарезервирован для системной категории.');
        }

        if ($this->isSystem && $this->systemCode !== $code) {
            throw new \DomainException('Код системной категории нельзя изменить.');
        }

        $this->systemCode = $code;

        return $this;
    }

    public static function normalizeCode(?string $code): ?string
    {
        $code = null === $code || '' === trim($code) ? null : strtoupper(trim($code));

        if (null !== $code && (strlen($code) > 32 || 1 !== preg_match('/^[A-Z0-9_]+$/', $code))) {
            throw new \DomainException('Код категории может содержать только латинские буквы, цифры и символ подчёркивания.');
        }

        return $code;
    }

    public static function isSystemCode(?string $code): bool
    {
        return null !== $code && in_array($code, self::SYSTEM_CODES, true);
    }

    public function markAsSystem(string $code): self
    {
        $code = self::normalizeCode($code);
        if (!self::isSystemCode($code)) {
            throw new \DomainException('Для системной категории указан неизвестный код.');
        }

        if ($this->isSystem && $this->systemCode !== $code) {
            throw new \DomainException('Код системной категории нельзя изменить.');
        }

        $this->systemCode = $code;
        $this->isSystem = true;

        return $this;
    }

    public function getFlowKind(): CashflowFlowKind
    {
        return $this->flowKind;
    }

    public function setFlowKind(CashflowFlowKind $kind): self
    {
        if ($this->isSystem && $this->flowKind !== $kind) {
            throw new \DomainException('Вид деятельности системной категории нельзя изменить.');
        }

        $this->flowKind = $kind;

        return $this;
    }

    public function acceptsRegularChildren(): bool
    {
        if (!$this->isSystem) {
            return CashflowFlowKind::TECHNICAL !== $this->getEffectiveFlowKind();
        }

        return in_array($this->systemCode, [
            self::CODE_OPERATING,
            self::CODE_FINANCING,
            self::CODE_INVESTING,
        ], true);
    }

    public function getEffectiveFlowKind(): CashflowFlowKind
    {
        return $this->parent?->getEffectiveFlowKind() ?? $this->flowKind;
    }

    public function syncFlowKindWithParent(): self
    {
        if (null !== $this->parent) {
            $this->flowKind = $this->parent->getEffectiveFlowKind();
        }

        return $this;
    }

    public function syncFlowKindSubtree(): self
    {
        $this->syncFlowKindWithParent();

        foreach ($this->children as $child) {
            $child->syncFlowKindSubtree();
        }

        return $this;
    }

    public function isSystem(): bool
    {
        return $this->isSystem;
    }

    public function setIsSystem(bool $v): self
    {
        if ($this->isSystem && !$v) {
            throw new \DomainException('Системную категорию нельзя сделать обычной.');
        }

        $this->isSystem = $v;

        return $this;
    }

    public function assertCanDelete(): void
    {
        if ($this->isSystem) {
            throw new \DomainException('Системную категорию нельзя удалить.');
        }

        if (!$this->children->isEmpty()) {
            throw new \DomainException('Нельзя удалить категорию, у которой есть дочерние статьи.');
        }
    }

    #[ORM\PreRemove]
    public function preventSystemCategoryRemoval(): void
    {
        $this->assertCanDelete();
    }

    public function getLevel(): int
    {
        return $this->parent ? $this->parent->getLevel() + 1 : 1;
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
