<?php

declare(strict_types=1);

namespace App\Cash\Application\DTO;

/**
 * Операция без статьи во входе задачи categorize_transactions.
 */
final readonly class CashCategorizationOperation
{
    public function __construct(
        public string $id,
        /** INFLOW | OUTFLOW */
        public string $direction,
        /** Десятичная строка, как в БД. */
        public string $amount,
        public string $currency,
        public \DateTimeImmutable $occurredAt,
        public ?string $description,
        public ?string $counterparty,
    ) {
    }
}
