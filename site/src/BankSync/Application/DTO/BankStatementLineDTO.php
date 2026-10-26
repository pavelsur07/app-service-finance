<?php

declare(strict_types=1);

namespace App\BankSync\Application\DTO;

use App\BankSync\Enum\BankDirection;

/**
 * Операция выписки в формате, не зависящем от банка.
 */
final readonly class BankStatementLineDTO
{
    /**
     * @param string $externalId идентификатор операции в банке — ключ дедупликации
     * @param string $amount сумма без знака, строкой с двумя знаками после точки
     * @param string|null $currency ISO-код из ответа банка; null — банк валюту не прислал
     * @param string|null $transactionId второй идентификатор операции, если банк его отдаёт
     */
    public function __construct(
        public string $externalId,
        public BankDirection $direction,
        public string $amount,
        public ?string $currency,
        public \DateTimeImmutable $occurredAt,
        public ?string $description,
        public ?string $transactionId = null,
    ) {
    }
}
