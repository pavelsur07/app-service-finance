<?php

declare(strict_types=1);

namespace App\Billing\Application\DTO;

use App\Billing\Enum\TariffCode;

/**
 * Назначение тарифа кабинету администратором. `until` = null — только для бессрочного тарифа.
 */
final readonly class AssignTariffCommand
{
    public function __construct(
        public string $companyId,
        public TariffCode $tariffCode,
        public \DateTimeImmutable $from,
        public ?\DateTimeImmutable $until,
    ) {
    }
}
