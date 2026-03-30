<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Api\Response;

use App\MarketplaceAnalytics\Domain\ValueObject\AnalysisPeriod;
use App\MarketplaceAnalytics\DTO\ListingUnitEconomics;

final readonly class SnapshotSummaryResponse
{
    /**
     * @param SnapshotListingSummary[] $listings
     */
    public function __construct(
        private string $dateFrom,
        private string $dateTo,
        private string $totalRevenue,
        private string $totalRefunds,
        private int $totalSalesQuantity,
        private int $totalOrdersQuantity,
        private array $listings,
    ) {}

    /**
     * @param ListingUnitEconomics[] $items
     */
    public static function fromUnitEconomics(AnalysisPeriod $period, array $items): self
    {
        $totalRevenue = '0.00';
        $totalRefunds = '0.00';
        $totalSalesQuantity = 0;
        $totalOrdersQuantity = 0;
        $listings = [];

        foreach ($items as $item) {
            $totalRevenue = bcadd($totalRevenue, $item->revenue, 2);
            $totalRefunds = bcadd($totalRefunds, $item->refunds, 2);
            $totalSalesQuantity += $item->salesQuantity;
            $totalOrdersQuantity += $item->ordersQuantity;
            $listings[] = SnapshotListingSummary::fromDTO($item);
        }

        return new self(
            dateFrom: $period->dateFrom->format('Y-m-d'),
            dateTo: $period->dateTo->format('Y-m-d'),
            totalRevenue: $totalRevenue,
            totalRefunds: $totalRefunds,
            totalSalesQuantity: $totalSalesQuantity,
            totalOrdersQuantity: $totalOrdersQuantity,
            listings: $listings,
        );
    }

    public function toArray(): array
    {
        return [
            'period' => [
                'date_from' => $this->dateFrom,
                'date_to' => $this->dateTo,
            ],
            'totals' => [
                'revenue' => $this->totalRevenue,
                'refunds' => $this->totalRefunds,
                'sales_quantity' => $this->totalSalesQuantity,
                'orders_quantity' => $this->totalOrdersQuantity,
            ],
            'listings' => array_map(
                static fn(SnapshotListingSummary $l) => $l->toArray(),
                $this->listings,
            ),
        ];
    }
}
