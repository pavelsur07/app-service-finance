<?php

namespace App\Entity;

use App\Enum\PLCategoryType;
use App\Enum\PLFlow;
use App\Enum\PlNature;
use App\Enum\PLValueFormat;
use App\Repository\PLCategoryRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Webmozart\Assert\Assert;

#[ORM\Entity(repositoryClass: PLCategoryRepository::class)]
#[ORM\Table(name: 'pl_categories')]
#[UniqueEntity(fields: ['company', 'code'], message: 'Код должен быть уникален в рамках компании.')]
class PLCategory
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Company $company;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'children')]
    private ?self $parent = null;

    #[ORM\OneToMany(targetEntity: self::class, mappedBy: 'parent')]
    #[ORM\OrderBy(['sortOrder' => 'ASC'])]
    private Collection $children;

    #[ORM\Column(type: 'integer')]
    private int $level = 1;

    #[ORM\Column(type: 'integer')]
    private int $sortOrder = 0;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $code = null; // стабильный код строки P&L (для ссылок в формулах)

    #[ORM\Column(enumType: PLCategoryType::class, options: ['default' => 'LEAF_INPUT'])]
    private PLCategoryType $type = PLCategoryType::LEAF_INPUT;

    #[ORM\Column(enumType: PLValueFormat::class, options: ['default' => 'MONEY'])]
    private PLValueFormat $format = PLValueFormat::MONEY;

    #[ORM\Column(enumType: PLFlow::class, options: ['default' => 'NONE'])]
    private PLFlow $flow = PLFlow::NONE;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 4, options: ['default' => '1.0000'])]
    private string $weightInParent = '1.0000'; // вес при суммировании родителем

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $isVisible = true; // управляет отображением строки в отчёте

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $formula = null; // формула для KPI/особого subtotal (пока только хранение)

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $calcOrder = null; // явный порядок расчёта (на будущее)

    public function __construct(string $id, Company $company)
    {
        Assert::uuid($id);
        $this->id = $id;
        $this->company = $company;
        $this->children = new ArrayCollection();
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
        $this->company = $company;

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

    public function getParent(): ?self
    {
        return $this->parent;
    }

    public function setParent(?self $parent): self
    {
        $this->parent = $parent;
        $this->level = $parent ? $parent->getLevel() + 1 : 1;
        Assert::range($this->level, 1, 5);

        return $this;
    }

    public function getChildren(): Collection
    {
        return $this->children;
    }

    public function getLevel(): int
    {
        return $this->level;
    }

    public function setLevel(int $level): self
    {
        $this->level = $level;

        return $this;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): self
    {
        $this->sortOrder = $sortOrder;

        return $this;
    }

    public function getRoot(): self
    {
        $node = $this;
        while (($parent = $node->getParent()) !== null) {
            $node = $parent;
        }

        return $node;
    }

    public function isIncomeRoot(): bool
    {
        $key = $this->resolveRootKey();

        $incomeKeys = [
            'REVENUE',
            'INCOME',
            'FINANCIAL_INCOME',
            'FIN_INCOME',
            'ВЫРУЧКА',
            'ДОХОДЫ',
            'ФИНАНСОВЫЕ_ДОХОДЫ',
        ];

        return in_array($key, $incomeKeys, true);
    }

    public function isExpenseRoot(): bool
    {
        $key = $this->resolveRootKey();

        $expenseKeys = [
            'COGS',
            'OPEX',
            'EXPENSE',
            'EXPENSES',
            'FINANCIAL_EXPENSE',
            'FIN_EXPENSE',
            'OTHER_EXPENSE',
            'COST_OF_GOODS_SOLD',
            'ПРОЧИЕ_РАСХОДЫ',
            'СЕБЕСТОИМОСТЬ',
            'ОПЕРАЦИОННЫЕ_РАСХОДЫ',
            'ФИНАНСОВЫЕ_РАСХОДЫ',
            'РАСХОДЫ',
        ];

        return in_array($key, $expenseKeys, true);
    }

    public function nature(): ?PlNature
    {
        return match ($this->flow) {
            PLFlow::INCOME => PlNature::INCOME,
            PLFlow::EXPENSE => PlNature::EXPENSE,
            default => null,
        };
    }

    private function resolveRootKey(): string
    {
        $root = $this->getRoot();

        $value = null;

        if (method_exists($root, 'getSlug')) {
            $slug = $root->getSlug();
            if (null !== $slug && '' !== $slug) {
                $value = $slug;
            }
        }

        if (null === $value && method_exists($root, 'getCode')) {
            $code = $root->getCode();
            if (null !== $code && '' !== $code) {
                $value = $code;
            }
        }

        if (null === $value) {
            $value = $root->getName();
        }

        $normalized = mb_strtoupper(trim((string) $value));
        $normalized = preg_replace('/[\s\-]+/u', '_', $normalized);

        return trim((string) $normalized, '_');
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(?string $code): self
    {
        $this->code = $code ? mb_strtoupper(trim($code)) : null;

        return $this;
    }

    public function getType(): PLCategoryType
    {
        return $this->type;
    }

    public function setType(PLCategoryType $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getFormat(): PLValueFormat
    {
        return $this->format;
    }

    public function setFormat(PLValueFormat $format): self
    {
        $this->format = $format;

        return $this;
    }

    public function getFlow(): PLFlow
    {
        return $this->flow;
    }

    public function setFlow(PLFlow $flow): self
    {
        $this->flow = $flow;

        return $this;
    }

    public function getWeightInParent(): string
    {
        return $this->weightInParent;
    }

    public function setWeightInParent(string $w): self
    {
        $this->weightInParent = $w;

        return $this;
    }

    public function isVisible(): bool
    {
        return $this->isVisible;
    }

    public function setIsVisible(bool $v): self
    {
        $this->isVisible = $v;

        return $this;
    }

    public function getFormula(): ?string
    {
        return $this->formula;
    }

    public function setFormula(?string $f): self
    {
        $this->formula = $f;

        return $this;
    }

    public function getCalcOrder(): ?int
    {
        return $this->calcOrder;
    }

    public function setCalcOrder(?int $o): self
    {
        $this->calcOrder = $o;

        return $this;
    }
}
