<?php

declare(strict_types=1);

namespace App\Balance\Entity;

use App\Balance\Enum\BalanceLinkSourceType;
use App\Balance\Repository\BalanceCategoryLinkRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Webmozart\Assert\Assert;

#[ORM\Entity(repositoryClass: BalanceCategoryLinkRepository::class)]
#[ORM\Table(name: 'balance_category_links')]
final class BalanceCategoryLink
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID, unique: true)]
    private string $id;

    #[ORM\Column(type: Types::GUID)]
    private string $companyId;

    #[ORM\ManyToOne(targetEntity: BalanceCategory::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private BalanceCategory $category;

    #[ORM\Column(type: Types::STRING, length: 50, enumType: BalanceLinkSourceType::class)]
    private BalanceLinkSourceType $sourceType;

    #[ORM\Column(type: Types::GUID, nullable: true)]
    private ?string $sourceId = null;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 1])]
    private int $sign = 1;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $position = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $id, string $companyId, BalanceCategory $category)
    {
        Assert::uuid($id);
        Assert::uuid($companyId);

        $this->id = $id;
        $this->companyId = $companyId;
        $this->category = $category;
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCompanyId(): string
    {
        return $this->companyId;
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
        $this->touch();

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
        $this->touch();

        return $this;
    }

    public function getSign(): int
    {
        return $this->sign;
    }

    public function setSign(int $sign): self
    {
        $this->sign = $sign;
        $this->touch();

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): self
    {
        $this->position = $position;
        $this->touch();

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
