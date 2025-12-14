<?php

namespace App\Balance\Entity;

use App\Balance\Enum\BalanceCategoryType;
use App\Entity\Company;
use App\Balance\Repository\BalanceCategoryRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Webmozart\Assert\Assert;

#[ORM\Entity(repositoryClass: BalanceCategoryRepository::class)]
#[ORM\Table(name: 'balance_categories')]
#[UniqueEntity(fields: ['company', 'code'], message: 'Код должен быть уникален в рамках компании.')]
class BalanceCategory
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Company $company;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $code = null;

    #[ORM\Column(enumType: BalanceCategoryType::class)]
    private BalanceCategoryType $type = BalanceCategoryType::ASSET;

    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'children')]
    private ?self $parent = null;

    /** @var Collection<int, self> */
    #[ORM\OneToMany(targetEntity: self::class, mappedBy: 'parent')]
    #[ORM\OrderBy(['sortOrder' => 'ASC'])]
    private Collection $children;

    #[ORM\Column(type: 'integer', options: ['default' => 1])]
    private int $level = 1;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $sortOrder = 0;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $isVisible = true;

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

    /**
     * @return Collection<int, self>
     */
    public function getChildren(): Collection
    {
        return $this->children;
    }

    public function getLevel(): int
    {
        return $this->level;
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

    public function getType(): BalanceCategoryType
    {
        return $this->type;
    }

    public function setType(BalanceCategoryType $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function isVisible(): bool
    {
        return $this->isVisible;
    }

    public function setIsVisible(bool $isVisible): self
    {
        $this->isVisible = $isVisible;

        return $this;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(?string $code): self
    {
        $this->code = $code;

        return $this;
    }
}
