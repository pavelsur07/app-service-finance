<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Application\Service;

use App\Marketplace\Domain\PlPreview\AdStatisticsCostCategories;
use App\Marketplace\DTO\ListingCostCategoryAggregateDTO;
use App\Marketplace\Enum\AdSpendKind;
use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceAds\Facade\MarketplaceAdsFacade;

final readonly class OzonOrderAdCoverage
{
    public function __construct(private MarketplaceAdsFacade $adsFacade)
    {
    }

    /**
     * @param array<string, list<ListingCostCategoryAggregateDTO>> $costs
     *
     * @return array<string, float> покрытые статистикой начисления по листингам
     */
    public function byListing(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to, array $costs): array
    {
        $netByListing = [];
        foreach ($costs as $listingId => $categories) {
            foreach ($categories as $category) {
                if (AdStatisticsCostCategories::payPerOrderCode() === $category->categoryCode) {
                    $netByListing[$listingId] = ($netByListing[$listingId] ?? 0.0) + (float) $category->netAmount;
                }
            }
        }

        $positiveNet = array_filter($netByListing, static fn (float $amount): bool => $amount > 0.0);
        if ([] === $positiveNet) {
            return [];
        }

        $statistics = $this->adsFacade->getAdSpendByListingForPeriod($companyId, $from, $to, MarketplaceType::OZON->value, AdSpendKind::PAY_PER_ORDER);
        foreach ($positiveNet as $listingId => $amount) {
            $positiveNet[$listingId] = min(round($amount, 2), max(0.0, (float) ($statistics[$listingId] ?? 0)));
        }

        return $positiveNet;
    }

    public function withoutListing(float $netAccrued, float $periodStatistics, float $alreadyCovered): float
    {
        return min(max(0.0, $netAccrued), max(0.0, round($periodStatistics - $alreadyCovered, 2)));
    }
}
