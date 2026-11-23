<?php

declare(strict_types=1);

namespace App\Cash\Application\DTO\PaymentIntake;

use App\Cash\Enum\Transaction\CashDirection;

final readonly class CreateMcpCashDraft
{
    public function __construct(
        public string $companyId,
        public string $actorUserId,
        public string $connectionId,
        public string $externalId,
        public string $moneyAccountId,
        public \DateTimeImmutable $date,
        public CashDirection $direction,
        public string $amount,
        public ?string $currency = null,
        public ?string $categoryId = null,
        public string $description = '',
    ) {
    }
}
