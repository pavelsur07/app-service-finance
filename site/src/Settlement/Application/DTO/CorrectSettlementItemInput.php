<?php

declare(strict_types=1);

namespace App\Settlement\Application\DTO;

use App\Shared\Domain\ValueObject\Money;

final readonly class CorrectSettlementItemInput
{
    public function __construct(public string $requestId, public string $itemId, public Money $amount, public bool $increase, public \DateTimeImmutable $effectiveDate, public string $reason)
    {
    }
}
