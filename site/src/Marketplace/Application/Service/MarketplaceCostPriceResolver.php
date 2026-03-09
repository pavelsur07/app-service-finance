<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Service;

use App\Catalog\Facade\ProductPurchasePriceFacade;
use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Entity\MarketplaceSale;

/**
 * Резолвер себестоимости для документов маркетплейса.
 * Единственное место логики получения costPrice.
 */
final class MarketplaceCostPriceResolver
{
    public function __construct(
        private readonly ProductPurchasePriceFacade $purchasePriceFacade,
    ) {
    }

    /**
     * Получить себестоимость для продажи.
     * Возвращает '0.00' если листинг не привязан к продукту или нет истории цен.
     */
    public function resolveForSale(MarketplaceListing $listing, \DateTimeImmutable $saleDate): string
    {
        return $this->resolve($listing, $saleDate);
    }

    /**
     * Получить себестоимость для возврата.
     *
     * Цепочка:
     * 1. Ищем продажу по srid → берём costPrice из продажи
     * 2. Если продажа не найдена или costPrice = 0 → ищем по order_dt из rawData
     * 3. Если order_dt отсутствует → возвращаем '0.00' (без fallback, без искажений)
     */
    public function resolveForReturn(
        MarketplaceListing $listing,
        ?MarketplaceSale $sale,
        ?array $rawData,
    ): string {
        // Шаг 1: берём из продажи если есть реальная себестоимость
        if ($sale !== null && $sale->getCostPrice() !== null && bccomp($sale->getCostPrice(), '0.00', 2) > 0) {
            return $sale->getCostPrice();
        }

        // Шаг 2: ищем по order_dt из rawData
        $orderDt = $rawData['order_dt'] ?? null;
        if ($orderDt !== null && $orderDt !== '') {
            try {
                $orderDate = new \DateTimeImmutable($orderDt);
                return $this->resolve($listing, $orderDate);
            } catch (\Exception) {
                // некорректная дата — не используем
            }
        }

        // Шаг 3: нет данных для точного определения — не искажаем
        return '0.00';
    }

    private function resolve(MarketplaceListing $listing, \DateTimeImmutable $date): string
    {
        $product = $listing->getProduct();
        if ($product === null) {
            return '0.00';
        }

        $companyId = (string) $listing->getCompany()->getId();
        $productId = (string) $product->getId();

        $dto = $this->purchasePriceFacade->getPurchasePriceAt($companyId, $productId, $date);
        if ($dto === null) {
            return '0.00';
        }

        return $dto->amount;
    }
}
