<?php

namespace App\Entity;

use App\Enum\PlNature;
use App\Repository\PLCategoryRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Webmozart\Assert\Assert;

#[ORM\Entity(repositoryClass: PLCategoryRepository::class)]
#[ORM\Table(name: 'pl_categories')]
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

    public function nature(): PlNature
    {
        return $this->isIncomeRoot() ? PlNature::INCOME : PlNature::EXPENSE;
    }

    private function resolveRootKey(): string
    {
        $root = $this->getRoot();

        $value = null;

        if (method_exists($root, 'getSlug')) {
            $slug = $root->getSlug();
            if ($slug !== null && $slug !== '') {
                $value = $slug;
            }
        }

        if ($value === null && method_exists($root, 'getCode')) {
            $code = $root->getCode();
            if ($code !== null && $code !== '') {
                $value = $code;
            }
        }

        if ($value === null) {
            $value = $root->getName();
        }

        $normalized = mb_strtoupper(trim((string) $value));
        $normalized = preg_replace('/[\s\-]+/u', '_', $normalized);

        return trim((string) $normalized, '_');
    }
}
