<?php

declare(strict_types=1);

namespace App\Settlement\Entity;

use App\Settlement\Enum\SettlementBucket;
use App\Shared\Domain\ValueObject\Money;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

#[ORM\Entity]
#[ORM\Table(name: 'settlement_items')]
#[ORM\UniqueConstraint(name: 'uniq_settlement_item_company_id', columns: ['company_id', 'id'])]
#[ORM\UniqueConstraint(name: 'uniq_settlement_item_document', columns: ['company_id', 'document_id'])]
class SettlementItem
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    public function __construct(
        #[ORM\Column(type: Types::GUID)]
        private string $companyId,
        #[ORM\Column(type: Types::GUID)]
        private string $documentId,
        #[ORM\Column(type: Types::GUID)]
        private string $counterpartyId,
        #[ORM\Column(enumType: SettlementBucket::class)]
        private SettlementBucket $bucket,
        #[ORM\Embedded(class: Money::class, columnPrefix: 'amount_')]
        private Money $amount,
        #[ORM\Column(type: Types::DATE_IMMUTABLE)]
        private \DateTimeImmutable $effectiveDate,
        #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
        private ?\DateTimeImmutable $dueDate = null,
        ?string $id = null,
    ) {
        Assert::uuid($companyId);
        Assert::uuid($documentId);
        Assert::uuid($counterpartyId);
        Assert::true($amount->isPositive());
        Assert::same($amount->currency(), 'RUB');
        $this->id = $id ?? Uuid::uuid7()->toString();
        Assert::uuid($this->id);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCompanyId(): string
    {
        return $this->companyId;
    }

    public function getDocumentId(): string
    {
        return $this->documentId;
    }

    public function getCounterpartyId(): string
    {
        return $this->counterpartyId;
    }

    public function getBucket(): SettlementBucket
    {
        return $this->bucket;
    }

    public function getAmount(): Money
    {
        return $this->amount;
    }

    public function getEffectiveDate(): \DateTimeImmutable
    {
        return $this->effectiveDate;
    }

    public function getDueDate(): ?\DateTimeImmutable
    {
        return $this->dueDate;
    }
}
