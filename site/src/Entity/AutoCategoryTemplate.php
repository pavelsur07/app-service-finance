<?php

namespace App\Entity;

use App\Enum\AutoTemplateDirection;
use App\Enum\AutoTemplateScope;
use App\Enum\MatchLogic;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class AutoCategoryTemplate
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private string $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Company $company;

    #[ORM\Column(enumType: AutoTemplateScope::class)]
    private AutoTemplateScope $scope = AutoTemplateScope::CASHFLOW;

    #[ORM\Column(length: 255)]
    private string $name = '';

    #[ORM\Column]
    private bool $isActive = true;

    #[ORM\Column(enumType: AutoTemplateDirection::class)]
    private AutoTemplateDirection $direction = AutoTemplateDirection::ANY;

    #[ORM\ManyToOne(targetEntity: CashflowCategory::class)]
    private ?CashflowCategory $targetCategory = null;

    #[ORM\Column(type: 'integer')]
    private int $priority = 100;

    #[ORM\Column(type: 'boolean')]
    private bool $stopOnMatch = false;

    #[ORM\Column(enumType: MatchLogic::class)]
    private MatchLogic $matchLogic = MatchLogic::ALL;

    #[ORM\OneToMany(mappedBy: 'template', targetEntity: AutoCategoryCondition::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $conditions;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $id, Company $company)
    {
        $this->id = $id;
        $this->company = $company;
        $this->conditions = new ArrayCollection();
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): string { return $this->id; }
    public function getCompany(): Company { return $this->company; }
    public function getScope(): AutoTemplateScope { return $this->scope; }
    public function setScope(AutoTemplateScope $scope): self { $this->scope = $scope; return $this; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }
    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $active): self { $this->isActive = $active; return $this; }
    public function getDirection(): AutoTemplateDirection { return $this->direction; }
    public function setDirection(AutoTemplateDirection $d): self { $this->direction = $d; return $this; }
    public function getTargetCategory(): ?CashflowCategory { return $this->targetCategory; }
    public function setTargetCategory(?CashflowCategory $c): self { $this->targetCategory = $c; return $this; }
    public function getPriority(): int { return $this->priority; }
    public function setPriority(int $p): self { $this->priority = $p; return $this; }
    public function getStopOnMatch(): bool { return $this->stopOnMatch; }
    public function setStopOnMatch(bool $s): self { $this->stopOnMatch = $s; return $this; }
    public function getMatchLogic(): MatchLogic { return $this->matchLogic; }
    public function setMatchLogic(MatchLogic $m): self { $this->matchLogic = $m; return $this; }
    /** @return Collection<int, AutoCategoryCondition> */
    public function getConditions(): Collection { return $this->conditions; }
    public function addCondition(AutoCategoryCondition $c): self { if(!$this->conditions->contains($c)){ $this->conditions->add($c); } return $this; }
    public function removeCondition(AutoCategoryCondition $c): self { $this->conditions->removeElement($c); return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(\DateTimeImmutable $d): self { $this->createdAt = $d; return $this; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function setUpdatedAt(\DateTimeImmutable $d): self { $this->updatedAt = $d; return $this; }
}
