<?php

declare(strict_types=1);

namespace App\Settlement\Application\DTO;

final readonly class RegisterCashSettlementInput
{
    public function __construct(public string $requestId, public string $cashTransactionId, public bool $isAdvance, public \DateTimeImmutable $effectiveDate)
    {
    }
}
