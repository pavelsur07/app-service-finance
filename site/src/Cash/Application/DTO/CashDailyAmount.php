<?php

declare(strict_types=1);

namespace App\Cash\Application\DTO;

/**
 * Сумма операций ДДС за один день, decimal string с двумя знаками.
 */
final readonly class CashDailyAmount
{
    public function __construct(
        public string $date,
        public string $amount,
    ) {
    }
}
