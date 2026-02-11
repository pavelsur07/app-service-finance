<?php

namespace App\Marketplace\Service;

use App\Catalog\Entity\Product;
use App\Company\Entity\Company;
use App\Marketplace\DTO\ProductMarginReport;
use App\Marketplace\Repository\MarketplaceCostRepository;
use App\Marketplace\Repository\MarketplaceReturnRepository;
use App\Marketplace\Repository\MarketplaceSaleRepository;

class MarketplaceAnalyticsService
{
    public function __construct(
        private readonly MarketplaceSaleRepository $saleRepository,
        private readonly MarketplaceCostRepository $costRepository,
        private readonly MarketplaceReturnRepository $returnRepository
    ) {}

    public function calculateProductMargin(
        Product $product,
        \DateTimeInterface $fromDate,
        \DateTimeInterface $toDate
    ): ProductMarginReport {
        // Продажи
        $sales = $this->saleRepository->findByProduct($product, $fromDate, $toDate);

        $totalRevenue = '0.00';
        $totalUnits = 0;

        foreach ($sales as $sale) {
            $totalRevenue = bcadd($totalRevenue, $sale->getTotalRevenue(), 2);
            $totalUnits += $sale->getQuantity();
        }

        // Затраты
        $costs = $this->costRepository->findByProduct($product, $fromDate, $toDate);

        $totalCosts = '0.00';
        foreach ($costs as $cost) {
            $totalCosts = bcadd($totalCosts, $cost->getAmount(), 2);
        }

        // Возвраты
        $returns = $this->returnRepository->findByProduct($product, $fromDate, $toDate);

        $totalRefunds = '0.00';
        $totalReturned = 0;

        foreach ($returns as $return) {
            $totalRefunds = bcadd($totalRefunds, $return->getRefundAmount(), 2);
            $totalReturned += $return->getQuantity();
        }

        // Себестоимость
        $netUnits = $totalUnits - $totalReturned;
        $cogs = bcmul($product->getPurchasePrice(), (string) $netUnits, 2);

        // Итоги
        $netRevenue = bcsub($totalRevenue, $totalRefunds, 2);
        $allCosts = bcadd($totalCosts, $cogs, 2);
        $grossProfit = bcsub($netRevenue, $allCosts, 2);

        $marginPercent = '0.00';
        if (bccomp($netRevenue, '0', 2) > 0) {
            $marginPercent = bcmul(
                bcdiv($grossProfit, $netRevenue, 4),
                '100',
                2
            );
        }

        return new ProductMarginReport(
            productId: $product->getId(),
            productName: $product->getName(),
            totalUnits: $totalUnits,
            returnedUnits: $totalReturned,
            netUnits: $netUnits,
            totalRevenue: $totalRevenue,
            refunds: $totalRefunds,
            netRevenue: $netRevenue,
            costs: $totalCosts,
            cogs: $cogs,
            grossProfit: $grossProfit,
            marginPercent: $marginPercent
        );
    }

    /**
     * @return ProductMarginReport[]
     */
    public function getTopProducts(
        Company $company,
        \DateTimeInterface $fromDate,
        \DateTimeInterface $toDate,
        int $limit = 10
    ): array {
        $products = $this->saleRepository->findProductsWithSales($company, $fromDate, $toDate);

        $reports = [];
        foreach ($products as $product) {
            $reports[] = $this->calculateProductMargin($product, $fromDate, $toDate);
        }

        // Сортировка по profit DESC
        usort($reports, fn($a, $b) => bccomp($b->grossProfit, $a->grossProfit, 2));

        return array_slice($reports, 0, $limit);
    }
}
