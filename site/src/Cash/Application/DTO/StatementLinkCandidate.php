<?php

declare(strict_types=1);

namespace App\Cash\Application\DTO;

/**
 * Активный денежный счёт компании, к которому можно привязать счёт банка.
 * `accountNumberDigits` — только цифры номера (null — номер не указан): по нему
 * BankSync подсказывает пару, но решение за клиентом.
 */
final readonly class StatementLinkCandidate
{
    public function __construct(
        public string $moneyAccountId,
        public string $name,
        public string $currency,
        public ?string $accountNumberDigits,
    ) {
    }
}
