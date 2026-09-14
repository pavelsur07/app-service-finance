<?php

declare(strict_types=1);

namespace App\Balance\Entity;

use App\Balance\Enum\BalanceCategoryType;
use App\Balance\Exception\BalanceDepthExceededException;
use App\Balance\Exception\BalanceLedgerException;
use App\Balance\Repository\BalanceCategoryRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Webmozart\Assert\Assert;

#[ORM\Entity(repositoryClass: BalanceCategoryRepository::class)]
#[ORM\Table(name: 'balance_articles')]
#[ORM\UniqueConstraint(name: 'uniq_balance_article_company_id', columns: ['company_id', 'id'])]
#[ORM\UniqueConstraint(name: 'uniq_balance_article_code', columns: ['company_id', 'code'])]
#[ORM\Index(name: 'idx_balance_article_parent', columns: ['company_id', 'parent_id'])]
class BalanceCategory
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID, unique: true)]
    private string $id;

    #[ORM\Column(type: Types::GUID)]
    private string $companyId;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $code = null;

    #[ORM\Column(type: Types::STRING, length: 50, enumType: BalanceCategoryType::class)]
    private BalanceCategoryType $type;

    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'children')]
    private ?self $parent = null;

    /** @var Collection<int, self> */
    #[ORM\OneToMany(targetEntity: self::class, mappedBy: 'parent')]
    #[ORM\OrderBy(['sortOrder' => 'ASC'])]
    private Collection $children;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 1])]
    private int $level = 1;

    #[ORM\Column(length: 20)]
    private string $kind = 'group';

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $isArchived = false;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $sortOrder = 0;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $isVisible = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $id, string $companyId)
    {
        Assert::uuid($id);
        Assert::uuid($companyId);

        $this->id = $id;
        $this->companyId = $companyId;
        $this->type = BalanceCategoryType::ASSET;
        $this->children = new ArrayCollection();
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

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
        $this->touch();

        return $this;
    }

    public function getParent(): ?self
    {
        return $this->parent;
    }

    public function setParent(?self $parent): self
    {
        $level = $parent ? $parent->getLevel() + 1 : 1;
        if ($level > 4) {
            throw new BalanceDepthExceededException(4);
        }
        if (null !== $parent && ($parent->getCompanyId() !== $this->companyId || $parent->getType() !== $this->type)) {
            throw new BalanceLedgerException('Родитель должен принадлежать той же компании и стороне баланса.');
        }
        if (null !== $parent && ('group' !== $parent->getKind() || $parent->isArchived())) {
            throw new BalanceLedgerException('Родитель должен быть активной группой.');
        }
        $this->parent = $parent;
        $this->level = $level;
        $this->touch();

        return $this;
    }

    /**
     * @return Collection<int, self>
     */
    public function getChildren(): Collection
    {
        return $this->children;
    }

    public function refreshLevel(): void
    {
        $level = null === $this->parent ? 1 : $this->parent->getLevel() + 1;
        if ($level > 4) {
            throw new BalanceDepthExceededException(4);
        }
        $this->level = $level;
        $this->touch();
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
        $this->touch();

        return $this;
    }

    public function getType(): BalanceCategoryType
    {
        return $this->type;
    }

    public function setType(BalanceCategoryType $type): self
    {
        $this->type = $type;
        $this->touch();

        return $this;
    }

    public function isVisible(): bool
    {
        return $this->isVisible;
    }

    public function setIsVisible(bool $isVisible): self
    {
        $this->isVisible = $isVisible;
        $this->touch();

        return $this;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(?string $code): self
    {
        $this->code = $code;
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

    public function getKind(): string
    {
        return $this->kind;
    }

    public function setKind(string $kind): self
    {
        Assert::inArray($kind, ['group', 'article']);
        $this->kind = $kind;
        $this->touch();

        return $this;
    }

    public function isArchived(): bool
    {
        return $this->isArchived;
    }

    public function setIsArchived(bool $archived): self
    {
        $this->isArchived = $archived;
        $this->touch();

        return $this;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
