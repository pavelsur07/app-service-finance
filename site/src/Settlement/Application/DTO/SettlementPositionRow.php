<?php

declare(strict_types=1);

namespace App\Settlement\Application\DTO;

use App\Settlement\Enum\SettlementBucket;
use App\Settlement\Enum\SettlementDocumentKind;
use App\Shared\Domain\ValueObject\Money;

final readonly class SettlementPositionRow
{
    public function __construct(public string $itemId, public string $documentId, public int $sequence, public string $counterpartyId, public SettlementBucket $bucket, public SettlementDocumentKind $kind, public Money $available, public \DateTimeImmutable $effectiveDate)
    {
    }

    public function label(): string
    {
        return '№'.$this->sequence.' · '.$this->effectiveDate->format('d.m.Y');
    }
}
