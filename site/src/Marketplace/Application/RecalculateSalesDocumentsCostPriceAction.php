<?php

declare(strict_types=1);

namespace App\Marketplace\Application;

use App\Marketplace\Application\Service\MarketplaceCostPriceResolver;
use App\Marketplace\DTO\RecalculateSalesCostPriceCommand;
use App\Marketplace\Repository\MarketplaceReturnRepository;
use App\Marketplace\Repository\MarketplaceSaleRepository;
use Doctrine\ORM\EntityManagerInterface;

final class RecalculateSalesDocumentsCostPriceAction
{
    public function __construct(
        private readonly MarketplaceSaleRepository   $saleRepository,
        private readonly MarketplaceReturnRepository $returnRepository,
        private readonly MarketplaceCostPriceResolver $costPriceResolver,
        private readonly EntityManagerInterface      $em,
    ) {
    }

    /**
     * @return array{sales: int, returns: int}
     */
    public function __invoke(RecalculateSalesCostPriceCommand $cmd): array
    {
        $salesCount   = 0;
        $returnsCount = 0;

        // --- Пересчёт продаж ---
        $sales = $this->saleRepository->findForCostRecalculation(
            $cmd->companyId,
            $cmd->marketplace,
            $cmd->dateFrom,
            $cmd->dateTo,
            $cmd->onlyZeroCost,
        );

        foreach ($sales as $sale) {
            $listing = $sale->getListing();

            $costPrice = $this->costPriceResolver->resolveForSale(
                $listing,
                $sale->getSaleDate(),
            );

            $sale->setCostPrice($costPrice);
            ++$salesCount;
        }

        // --- Пересчёт возвратов ---
        $returns = $this->returnRepository->findForCostRecalculation(
            $cmd->companyId,
            $cmd->marketplace,
            $cmd->dateFrom,
            $cmd->dateTo,
            $cmd->onlyZeroCost,
        );

        foreach ($returns as $return) {
            $costPrice = $this->costPriceResolver->resolveForReturn(
                $return->getListing(),
                $return->getSale(),
                $return->getRawData(),
            );

            $return->setCostPrice($costPrice);
            ++$returnsCount;
        }

        // Единый flush после всех изменений
        $this->em->flush();

        return ['sales' => $salesCount, 'returns' => $returnsCount];
    }
}
