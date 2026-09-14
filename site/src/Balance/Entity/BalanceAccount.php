<?php

declare(strict_types=1);

namespace App\Balance\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

#[ORM\Entity]
#[ORM\Table(name: 'balance_accounts')]
#[ORM\UniqueConstraint(name: 'uniq_balance_account_company_id', columns: ['company_id', 'id'])]
#[ORM\UniqueConstraint(name: 'uniq_balance_account_code', columns: ['company_id', 'code'])]
#[ORM\Index(name: 'idx_balance_account_article', columns: ['company_id', 'article_id'])]
class BalanceAccount
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\Column(type: Types::GUID)]
    private string $companyId;

    #[ORM\Column(type: Types::GUID)]
    private string $articleId;

    #[ORM\Column(length: 64)]
    private string $code;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $allowNegative = false;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $isArchived = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $companyId, string $articleId, string $code, string $name, bool $allowNegative = false)
    {
        Assert::uuid($companyId);
        $this->id = Uuid::uuid7()->toString();
        $this->companyId = $companyId;
        Assert::uuid($articleId);
        Assert::notWhitespaceOnly($code);
        Assert::maxLength($code, 64);
        Assert::notWhitespaceOnly($name);
        Assert::maxLength($name, 255);
        $this->articleId = $articleId;
        $this->code = $code;
        $this->name = $name;
        $this->allowNegative = $allowNegative;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCompanyId(): string
    {
        return $this->companyId;
    }

    public function getArticleId(): string
    {
        return $this->articleId;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function allowsNegative(): bool
    {
        return $this->allowNegative;
    }

    public function isArchived(): bool
    {
        return $this->isArchived;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
