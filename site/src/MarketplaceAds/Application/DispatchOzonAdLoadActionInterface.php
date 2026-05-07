<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Application;

use App\MarketplaceAds\Entity\AdLoadJob;

interface DispatchOzonAdLoadActionInterface
{
    public function __invoke(
        string $companyId,
        \DateTimeImmutable $dateFrom,
        \DateTimeImmutable $dateTo,
    ): AdLoadJob;
}
