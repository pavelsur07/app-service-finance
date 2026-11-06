<?php

declare(strict_types=1);

namespace App\Settlement\Entity;

use App\Shared\Domain\ValueObject\Money;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

#[ORM\Entity]
#[ORM\Table(name: 'settlement_allocations')]
#[ORM\UniqueConstraint(name: 'uniq_settlement_allocations_company_id', columns: ['company_id', 'id'])]
#[ORM\UniqueConstraint(name: 'uniq_settlement_allocation_document', columns: ['company_id', 'document_id'])]
class SettlementAllocation
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
        private string $obligationItemId,
        #[ORM\Column(type: Types::GUID)]
        private string $paymentItemId,
        #[ORM\Embedded(class: Money::class, columnPrefix: 'amount_')]
        private Money $amount,
        #[ORM\Column(type: Types::DATE_IMMUTABLE)]
        private \DateTimeImmutable $effectiveDate,
        ?string $id = null,
    ) {
        $this->id = $id ?? Uuid::uuid7()->toString();
        Assert::uuid($this->id);
        Assert::uuid($companyId);
        Assert::uuid($documentId);
        Assert::uuid($obligationItemId);
        Assert::uuid($paymentItemId);
        Assert::true($amount->isPositive());
        Assert::same($amount->currency(), 'RUB');
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

    public function getObligationItemId(): string
    {
        return $this->obligationItemId;
    }

    public function getPaymentItemId(): string
    {
        return $this->paymentItemId;
    }

    public function getAmount(): Money
    {
        return $this->amount;
    }

    public function getEffectiveDate(): \DateTimeImmutable
    {
        return $this->effectiveDate;
    }
}
