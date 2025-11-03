<?php

namespace App\Banking\Dto;

final class TransactionDto
{
    public function __construct(
        public string $externalId,        // стабильный внешний ИД транзакции (или дет. хеш)
        public string $accountExternalId, // ИД счёта в банке
        public int $amountMinor,          // сумма в минорных единицах (абсолютное значение!)
        public string $currency,          // 'RUB', 'USD', ...
        public string $direction,         // 'in' | 'out'
        public \DateTimeImmutable $postedAt, // дата проводки/списания в банке
        public string $description,
        public ?string $counterparty = null,
        public ?array $raw = null,         // опционально: исходные поля для диагностики
    ) {
    }
}
