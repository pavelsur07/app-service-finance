<?php

namespace App\Banking\Dto;

final class AccountDto
{
    public function __construct(
        public string $externalId,     // ИД счёта в банке
        public string $number,         // номер/IBAN: для отображения/матчинга
        public string $currency,       // 'RUB', 'USD', ...
        public ?string $displayName = null,
    ) {
    }
}
