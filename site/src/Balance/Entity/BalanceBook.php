<?php

declare(strict_types=1);

namespace App\Balance\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

#[ORM\Entity]
#[ORM\Table(name: 'balance_books')]
#[ORM\UniqueConstraint(name: 'uniq_balance_book_company', columns: ['company_id'])]
class BalanceBook
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\Column(type: Types::GUID)]
    private string $companyId;

    #[ORM\Column(length: 3, nullable: true)]
    private ?string $currency = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $startDate = null;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $initialized = false;

    #[ORM\Column(type: Types::INTEGER)]
    private int $version = 0;

    #[ORM\Column(type: Types::BIGINT)]
    private string $nextDocumentNumber = '1';

    #[ORM\Column(type: Types::BIGINT)]
    private string $nextPostingSequence = '1';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $companyId, ?string $currency = null, ?\DateTimeImmutable $startDate = null)
    {
        Assert::uuid($companyId);
        $this->id = Uuid::uuid7()->toString();
        $this->companyId = $companyId;
        if (null !== $currency) {
            Assert::regex($currency, '/^[A-Z]{3}$/D');
        }
        $this->currency = $currency;
        $this->startDate = $startDate;
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

    public function getCurrency(): ?string
    {
        return $this->currency;
    }

    public function getStartDate(): ?\DateTimeImmutable
    {
        return $this->startDate;
    }

    public function isInitialized(): bool
    {
        return $this->initialized;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function getNextDocumentNumber(): string
    {
        return $this->nextDocumentNumber;
    }

    public function getNextPostingSequence(): string
    {
        return $this->nextPostingSequence;
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
