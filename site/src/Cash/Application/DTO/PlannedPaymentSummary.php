<?php

declare(strict_types=1);

namespace App\Cash\Application\DTO;

/**
 * Плановый платёж (статус PLANNED или APPROVED); сумма — decimal string.
 */
final readonly class PlannedPaymentSummary
{
    public function __construct(
        public \DateTimeImmutable $plannedAt,
        public string $amount,
        public string $categoryName,
        public ?string $counterpartyName,
    ) {
    }
}
