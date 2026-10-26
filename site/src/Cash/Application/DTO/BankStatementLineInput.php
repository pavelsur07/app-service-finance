<?php

declare(strict_types=1);

namespace App\Cash\Application\DTO;

/**
 * Операция банковской выписки для импорта в ДДС.
 */
final readonly class BankStatementLineInput
{
    /**
     * @param string $externalId идентификатор операции в банке — ключ повтора в пределах источника
     * @param bool $isInflow поступление на счёт; false — списание
     * @param string $amount сумма без знака, строкой с двумя знаками после точки
     * @param string|null $currency ISO-код; null — валюта счёта
     */
    public function __construct(
        public string $externalId,
        public bool $isInflow,
        public string $amount,
        public ?string $currency,
        public \DateTimeImmutable $occurredAt,
        public ?string $description,
    ) {
    }
}
