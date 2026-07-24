<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Application;

use App\MarketplaceAds\Application\DTO\WbAdSpendLoadResult;

interface LoadWbAdSpendDayActionInterface
{
    public function __invoke(
        string $companyId,
        string $connectionId,
        \DateTimeImmutable $date,
    ): WbAdSpendLoadResult;
}
