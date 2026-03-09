<?php

declare(strict_types=1);

namespace App\Marketplace\Application;

use App\Marketplace\DTO\RecalculateSalesCostPriceCommand;
use App\Marketplace\Repository\MarketplaceReturnRepository;
use App\Marketplace\Repository\MarketplaceSaleRepository;
use App\Catalog\Facade\ProductPurchasePriceFacade;
use Doctrine\ORM\EntityManagerInterface;

final class RecalculateSalesDocumentsCostPriceAction
{
    public function __construct(
        private readonly MarketplaceSaleRepository   $saleRepository,
        private readonly MarketplaceReturnRepository $returnRepository,
        private readonly ProductPurchasePriceFacade  $purchasePriceFacade,
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

        // Пересчёт продаж
        $sales = $this->saleRepository->findForCostRecalculation(
            $cmd->companyId,
            $cmd->marketplace,
            $cmd->dateFrom,
            $cmd->dateTo,
            $cmd->onlyZeroCost,
        );

        foreach ($sales as $sale) {
            $listing = $sale->getListing();
            $product = $listing->getProduct();

            if ($product === null) {
                continue;
            }

            $dto = $this->purchasePriceFacade->getPurchasePriceAt(
                $cmd->companyId,
                (string) $product->getId(),
                $sale->getSaleDate(),
            );

            $sale->setCostPrice($dto?->amount ?? '0.00');
            ++$salesCount;
        }

        // Пересчёт возвратов
        $returns = $this->returnRepository->findForCostRecalculation(
            $cmd->companyId,
            $cmd->marketplace,
            $cmd->dateFrom,
            $cmd->dateTo,
            $cmd->onlyZeroCost,
        );

        foreach ($returns as $return) {
            $listing = $return->getListing();
            $product = $listing->getProduct();

            if ($product === null) {
                continue;
            }

            // Берём себестоимость из связанной продажи если есть
            $sale = $return->getSale();
            if ($sale !== null && $sale->getCostPrice() !== null && $sale->getCostPrice() > '0.00') {
                $return->setCostPrice($sale->getCostPrice());
            } else {
                $dto = $this->purchasePriceFacade->getPurchasePriceAt(
                    $cmd->companyId,
                    (string) $product->getId(),
                    $return->getReturnDate(),
                );
                $return->setCostPrice($dto?->amount ?? '0.00');
            }

            ++$returnsCount;
        }

        // Единый flush после всех изменений
        $this->em->flush();

        return ['sales' => $salesCount, 'returns' => $returnsCount];
    }
}
