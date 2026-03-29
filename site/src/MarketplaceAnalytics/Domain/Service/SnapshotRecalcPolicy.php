<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Domain\Service;

use App\Marketplace\Facade\MarketplaceFacade;
use App\MarketplaceAnalytics\Domain\ValueObject\AnalysisPeriod;

final readonly class SnapshotRecalcPolicy
{
    public function __construct(
        private SnapshotCalculationPolicy $snapshotCalculationPolicy,
        private MarketplaceFacade $marketplaceFacade,
    ) {}

    public function recalcBySchedule(string $companyId, int $lookbackDays): void
    {
        $listings = $this->marketplaceFacade->getActiveListings($companyId, null);
        $today = new \DateTimeImmutable('today');

        for ($i = 1; $i <= $lookbackDays; $i++) {
            $date = $today->modify("-{$i} day");

            foreach ($listings as $listing) {
                $this->snapshotCalculationPolicy->calculateForListingDay(
                    $companyId,
                    $listing['id'],
                    $date,
                );
            }
        }
    }

    public function recalcByUserRequest(string $companyId, AnalysisPeriod $period): void
    {
        $listings = $this->marketplaceFacade->getActiveListings($companyId, null);
        $current = $period->dateFrom;

        while ($current <= $period->dateTo) {
            foreach ($listings as $listing) {
                $this->snapshotCalculationPolicy->calculateForListingDay(
                    $companyId,
                    $listing['id'],
                    $current,
                );
            }

            $current = $current->modify('+1 day');
        }
    }
}
