<?php

declare(strict_types=1);

namespace App\Cash\Application\DTO;

/**
 * Остаток счёта за день из регистра ежедневных остатков; суммы — decimal string.
 */
final readonly class MoneyAccountDailyBalanceRow
{
    public function __construct(
        public string $date,
        public string $accountId,
        public string $openingBalance,
        public string $closingBalance,
    ) {
    }
}
