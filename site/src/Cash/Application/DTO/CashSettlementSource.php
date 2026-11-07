<?php

declare(strict_types=1);

namespace App\Cash\Application\DTO;

use App\Cash\Enum\Transaction\CashDirection;
use App\Shared\Domain\ValueObject\Money;

final readonly class CashSettlementSource
{
    public function __construct(public string $id, public string $companyId, public ?string $counterpartyId, public Money $amount, public \DateTimeImmutable $occurredAt, public CashDirection $direction, public bool $deleted, public bool $isTransfer, public string $fingerprint)
    {
    }
}
