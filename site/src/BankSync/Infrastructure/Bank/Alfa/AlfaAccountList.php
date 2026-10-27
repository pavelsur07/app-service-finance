<?php

declare(strict_types=1);

namespace App\BankSync\Infrastructure\Bank\Alfa;

use App\BankSync\Application\DTO\BankAccountDTO;

/**
 * Список счетов Альфы после разбора.
 */
final readonly class AlfaAccountList
{
    /**
     * @param list<BankAccountDTO> $accounts
     * @param int $skippedAccounts счета без номера `RU.CBR.BBAN` или валюты
     */
    public function __construct(
        public array $accounts,
        public int $skippedAccounts,
    ) {
    }
}
