<?php

declare(strict_types=1);

namespace App\Cash\Application\DTO;

use App\Cash\Enum\Accounts\MoneyAccountType;

/**
 * Денежный счёт для отчётов других модулей; суммы — decimal string.
 */
final readonly class MoneyAccountSummary
{
    public function __construct(
        public string $id,
        public string $name,
        public MoneyAccountType $type,
        public string $currency,
        public string $openingBalance,
        public \DateTimeImmutable $openingBalanceDate,
    ) {
    }
}
