<?php

declare(strict_types=1);

namespace App\Cash\Application\DTO;

/**
 * Денежный счёт компании — цель импорта выписки (по привязке счёта банка в BankSync).
 */
final readonly class StatementImportAccount
{
    public function __construct(
        public string $moneyAccountId,
        public string $currency,
    ) {
    }
}
