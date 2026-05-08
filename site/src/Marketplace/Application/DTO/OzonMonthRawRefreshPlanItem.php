<?php

declare(strict_types=1);

namespace App\Marketplace\Application\DTO;

final readonly class OzonMonthRawRefreshPlanItem
{
    public function __construct(
        public string $companyId,
        public string $connectionId,
        public string $marketplace,
        public string $date,
        public string $status,
        public ?string $skippedReason,
    ) {
    }
}
