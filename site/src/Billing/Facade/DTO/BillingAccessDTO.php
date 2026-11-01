<?php

declare(strict_types=1);

namespace App\Billing\Facade\DTO;

use App\Billing\Enum\AccessStatus;
use App\Billing\Enum\TariffCode;

/**
 * Доступ компании на дату. `tariffCode`/`tariffName` = null — подписки нет;
 * `paidUntil` = null — подписки нет или тариф бессрочный.
 */
final readonly class BillingAccessDTO
{
    public function __construct(
        public AccessStatus $status,
        public ?TariffCode $tariffCode,
        public ?string $tariffName,
        public ?\DateTimeImmutable $paidUntil,
        public ?int $daysLeft,
    ) {
    }
}
