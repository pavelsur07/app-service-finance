<?php

declare(strict_types=1);

namespace App\Cash\Application\DTO;

/**
 * Входящий остаток счёта на начало дня для отчёта ДДС; decimal string.
 */
final readonly class MoneyAccountOpeningBalance
{
    public function __construct(
        public string $accountId,
        public string $currency,
        public string $openingBalance,
    ) {
    }
}
