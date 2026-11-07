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
#[ORM\Table(name: 'settlement_movements')]
#[ORM\UniqueConstraint(name: 'uniq_settlement_movements_company_id', columns: ['company_id', 'id'])]
#[ORM\UniqueConstraint(name: 'uniq_settlement_movement_event', columns: ['company_id', 'document_id', 'item_id', 'bucket'])]
class SettlementMovement
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
        private string $itemId,
        #[ORM\Column(enumType: SettlementBucket::class)]
        private SettlementBucket $bucket,
        #[ORM\Embedded(class: Money::class, columnPrefix: 'delta_')]
        private Money $delta,
        #[ORM\Column(type: Types::DATE_IMMUTABLE)]
        private \DateTimeImmutable $effectiveDate,
        ?string $id = null,
    ) {
        $this->id = $id ?? Uuid::uuid7()->toString();
        Assert::uuid($this->id);
        Assert::uuid($companyId);
        Assert::uuid($documentId);
        Assert::uuid($itemId);
        Assert::same($delta->currency(), 'RUB');
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

    public function getItemId(): string
    {
        return $this->itemId;
    }

    public function getBucket(): SettlementBucket
    {
        return $this->bucket;
    }

    public function getDelta(): Money
    {
        return $this->delta;
    }

    public function getEffectiveDate(): \DateTimeImmutable
    {
        return $this->effectiveDate;
    }
}
