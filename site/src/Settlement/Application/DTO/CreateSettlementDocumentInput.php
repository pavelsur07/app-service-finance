<?php

declare(strict_types=1);

namespace App\Settlement\Application\DTO;

use App\Settlement\Enum\SettlementBucket;
use App\Settlement\Enum\SettlementDocumentKind;
use App\Shared\Domain\ValueObject\Money;

final readonly class CreateSettlementDocumentInput
{
    public function __construct(public string $requestId, public SettlementDocumentKind $kind, public string $counterpartyId, public SettlementBucket $bucket, public Money $amount, public \DateTimeImmutable $effectiveDate, public ?\DateTimeImmutable $dueDate, public string $reason)
    {
    }
}
