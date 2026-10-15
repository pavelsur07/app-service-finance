<?php

declare(strict_types=1);

namespace App\Cash\Application\DTO;

use App\Cash\Enum\Transaction\CashDirection;

/**
 * Строка отчёта ДДС: строка разбивки транзакции. У транзакции без разбивки
 * `categoryId` и `amount` — null (LEFT JOIN), отчёт такие строки пропускает.
 */
final readonly class CashflowReportRow
{
    public function __construct(
        public ?string $categoryId,
        public ?string $projectId,
        public ?string $projectName,
        public ?string $responsibilityCenterId,
        public CashDirection $direction,
        public ?string $amount,
        public string $currency,
        public \DateTimeImmutable $occurredAt,
    ) {
    }
}
