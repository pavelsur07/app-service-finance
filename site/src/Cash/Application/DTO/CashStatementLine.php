<?php

declare(strict_types=1);

namespace App\Cash\Application\DTO;

use App\Cash\Enum\Transaction\CashDirection;

/**
 * Строка выписки: строка разбивки транзакции ДДС либо вся транзакция, если
 * разбивки нет (`categoryId` = null). `amount` — сумма строки как хранится.
 */
final readonly class CashStatementLine
{
    public function __construct(
        public \DateTimeImmutable $occurredAt,
        public ?string $externalId,
        public ?string $counterpartyName,
        public string $description,
        public CashDirection $direction,
        public string $amount,
        public ?string $categoryId,
        public ?string $categoryName,
    ) {
    }
}
