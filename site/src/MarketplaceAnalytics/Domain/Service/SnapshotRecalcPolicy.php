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
        if ($lookbackDays <= 0) {
            return;
        }

        $today = new \DateTimeImmutable('today');
        $period = new AnalysisPeriod(
            $today->modify("-{$lookbackDays} day"),
            $today->modify('-1 day'),
        );

        $this->recalcByUserRequest($companyId, $period);
    }

    public function recalcByUserRequest(string $companyId, AnalysisPeriod $period): void
    {
        $listings = $this->marketplaceFacade->getActiveListings($companyId, null);
        $current = $period->dateFrom;

        while ($current <= $period->dateTo) {
            foreach ($listings as $listing) {
                $this->snapshotCalculationPolicy->calculateForListingDay(
                    $companyId,
                    $listing->id,
                    $current,
                    $listing->marketplace,
                );
            }

            $current = $current->modify('+1 day');
        }
    }
}
