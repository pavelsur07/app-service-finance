<?php

declare(strict_types=1);

namespace App\Cash\Application\DTO;

use App\Cash\Enum\Transaction\CashDirection;
use App\Shared\Domain\ValueObject\Money;

final readonly class IntragroupTransaction
{
    /** @param array<string, string> $splits категория => абсолютная сумма строки в RUB */
    public function __construct(
        public string $id,
        public string $companyId,
        public ?string $companyInn,
        public ?string $counterpartyInn,
        public CashDirection $direction,
        public \DateTimeImmutable $occurredAt,
        public Money $amount,
        public string $accountLabel = '',
        public array $splits = [],
    ) {
    }
}
