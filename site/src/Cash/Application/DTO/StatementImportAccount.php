<?php

declare(strict_types=1);

namespace App\Cash\Application\DTO;

/**
 * Денежный счёт компании, найденный по номеру банковского счёта, — цель импорта выписки.
 */
final readonly class StatementImportAccount
{
    public function __construct(
        public string $moneyAccountId,
        public string $currency,
    ) {
    }
}
