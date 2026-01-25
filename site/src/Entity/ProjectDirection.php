<?php

namespace App\Entity;

use App\Company\Entity\Company;
use App\Repository\ProjectDirectionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Webmozart\Assert\Assert;

#[ORM\Entity(repositoryClass: ProjectDirectionRepository::class)]
#[ORM\Table(name: 'project_directions')]
class ProjectDirection
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Company $company;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(type: 'integer')]
    private int $sort = 0;

    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'children')]
    private ?self $parent = null;

    #[ORM\OneToMany(targetEntity: self::class, mappedBy: 'parent')]
    #[ORM\OrderBy(['sort' => 'ASC'])]
    private Collection $children;

    public function __construct(string $id, Company $company, string $name)
    {
        Assert::uuid($id);
        $this->id = $id;
        $this->company = $company;
        $this->name = $name;
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

    public function getSort(): int
    {
        return $this->sort;
    }

    public function setSort(int $sort): self
    {
        $this->sort = $sort;

        return $this;
    }

    public function getParent(): ?self
    {
        return $this->parent;
    }

    public function setParent(?self $parent): self
    {
        $this->parent = $parent;

        return $this;
    }

    public function getChildren(): Collection
    {
        return $this->children;
    }

    public function getLevel(): int
    {
        return $this->parent ? $this->parent->getLevel() + 1 : 1;
    }

    public function getIndentedName(string $char = '—'): string
    {
        return str_repeat($char, max(0, $this->getLevel() - 1)).' '.$this->name;
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
