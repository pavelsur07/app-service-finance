<?php

declare(strict_types=1);

namespace App\Settlement\Application\DTO;

use App\Settlement\Enum\SettlementBucket;
use App\Settlement\Enum\SettlementSourceType;
use App\Shared\Domain\ValueObject\Money;

final readonly class SettlementMovementRow
{
    public function __construct(public string $documentId, public string $itemId, public \DateTimeImmutable $effectiveDate, public SettlementBucket $bucket, public Money $delta, public SettlementSourceType $sourceType, public ?string $sourceId, public string $reason)
    {
    }
}
