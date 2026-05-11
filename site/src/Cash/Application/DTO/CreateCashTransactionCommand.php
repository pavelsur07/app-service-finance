<?php

declare(strict_types=1);

namespace App\Cash\Application\DTO;

use App\Cash\Enum\Transaction\CashDirection;

final readonly class CreateCashTransactionCommand
{
    public function __construct(
        public string $companyId,
        public string $moneyAccountId,
        public CashDirection $direction,
        public string $amount,
        public string $currency,
        public \DateTimeImmutable $occurredAt,
        public ?string $description = null,
        public ?string $counterpartyId = null,
        public ?string $cashflowCategoryId = null,
        public ?string $projectDirectionId = null,
        public ?string $importSource = null,
        public ?string $externalId = null,
        public ?string $dedupeHash = null,
        public ?array $rawData = null,
    ) {}
}
