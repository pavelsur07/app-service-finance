<?php

namespace App\Entity;

use App\Enum\CashflowCategoryStatus;
use App\Repository\CashflowCategoryRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Webmozart\Assert\Assert;

#[ORM\Entity(repositoryClass: CashflowCategoryRepository::class)]
#[ORM\Table(name: '`cashflow_categories`')]
class CashflowCategory
{
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

    #[ORM\OneToMany(targetEntity: self::class, mappedBy: 'parent', orphanRemoval: true)]
    #[ORM\OrderBy(['sort' => 'ASC'])]
    private Collection $children;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Company $company;

    public function __construct(string $id, Company $company)
    {
        Assert::uuid($id);
        $this->id = $id;
        $this->company = $company;
        $this->status = CashflowCategoryStatus::ACTIVE;
        $this->children = new ArrayCollection();
    }

    public function getId(): ?string { return $this->id; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): self { $this->description = $description; return $this; }
    public function getStatus(): CashflowCategoryStatus { return $this->status; }
    public function setStatus(CashflowCategoryStatus $status): self { $this->status = $status; return $this; }
    public function getSort(): int { return $this->sort; }
    public function setSort(int $sort): self { $this->sort = $sort; return $this; }
    public function getParent(): ?self { return $this->parent; }
    public function setParent(?self $parent): self { $this->parent = $parent; return $this; }
    public function getChildren(): Collection { return $this->children; }
    public function getCompany(): Company { return $this->company; }
    public function setCompany(Company $company): self { $this->company = $company; return $this; }

    public function getLevel(): int
    {
        return $this->parent ? $this->parent->getLevel() + 1 : 1;
    }
}
