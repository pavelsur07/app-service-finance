<?php

namespace App\Balance\Entity;

use App\Balance\Enum\BalanceLinkSourceType;
use App\Balance\Repository\BalanceCategoryLinkRepository;
use App\Entity\Company;
use Doctrine\ORM\Mapping as ORM;
use Webmozart\Assert\Assert;

#[ORM\Entity(repositoryClass: BalanceCategoryLinkRepository::class)]
#[ORM\Table(name: 'balance_category_links')]
class BalanceCategoryLink
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Company $company;

    #[ORM\ManyToOne(targetEntity: BalanceCategory::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private BalanceCategory $category;

    #[ORM\Column(enumType: BalanceLinkSourceType::class)]
    private BalanceLinkSourceType $sourceType;

    #[ORM\Column(type: 'guid', nullable: true)]
    private ?string $sourceId = null;

    #[ORM\Column(type: 'integer', options: ['default' => 1])]
    private int $sign = 1;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $position = 0;

    public function __construct(string $id, Company $company, BalanceCategory $category)
    {
        Assert::uuid($id);
        $this->id = $id;
        $this->company = $company;
        $this->category = $category;
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function getCategory(): BalanceCategory
    {
        return $this->category;
    }

    public function getSourceType(): BalanceLinkSourceType
    {
        return $this->sourceType;
    }

    public function setSourceType(BalanceLinkSourceType $sourceType): self
    {
        $this->sourceType = $sourceType;

        return $this;
    }

    public function getSourceId(): ?string
    {
        return $this->sourceId;
    }

    public function setSourceId(?string $sourceId): self
    {
        if (null !== $sourceId) {
            Assert::uuid($sourceId);
        }

        $this->sourceId = $sourceId;

        return $this;
    }

    public function getSign(): int
    {
        return $this->sign;
    }

    public function setSign(int $sign): self
    {
        $this->sign = $sign;

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): self
    {
        $this->position = $position;

        return $this;
    }
}
