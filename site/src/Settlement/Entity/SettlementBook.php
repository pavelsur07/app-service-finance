<?php

declare(strict_types=1);

namespace App\Settlement\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

#[ORM\Entity]
#[ORM\Table(name: 'settlement_books')]
#[ORM\UniqueConstraint(name: 'uniq_settlement_books_company_id', columns: ['company_id', 'id'])]
#[ORM\UniqueConstraint(name: 'uniq_settlement_book_company', columns: ['company_id'])]
class SettlementBook
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    public function __construct(
        #[ORM\Column(type: Types::GUID)]
        private string $companyId,
        #[ORM\Column(type: Types::DATE_IMMUTABLE)]
        private \DateTimeImmutable $startDate,
        ?string $id = null,
    ) {
        $this->id = $id ?? Uuid::uuid7()->toString();
        Assert::uuid($this->id);
        Assert::uuid($companyId);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCompanyId(): string
    {
        return $this->companyId;
    }

    public function getStartDate(): \DateTimeImmutable
    {
        return $this->startDate;
    }
    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $initialized = false;
    #[ORM\Column(type: Types::INTEGER)]
    private int $version = 1;

    public function isInitialized(): bool
    {
        return $this->initialized;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function initialize(): void
    {
        $this->initialized = true;
        ++$this->version;
    }
}
