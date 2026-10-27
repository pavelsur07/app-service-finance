<?php

declare(strict_types=1);

namespace App\BankSync\Application\DTO;

/**
 * Счёт клиента в банке, как его отдал адаптер. `currency` — ISO 4217 в верхнем регистре
 * (`RUR` уже заменён на `RUB`); `enabled` — банк разрешает операции по счёту;
 * `externalId` — идентификатор счёта у банка (у Альфы `accountId`), если он есть.
 */
final readonly class BankAccountDTO
{
    public function __construct(
        public string $accountNumber,
        public string $currency,
        public bool $enabled = true,
        public ?string $bic = null,
        public ?string $description = null,
        public ?string $externalId = null,
    ) {
    }
}
