<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Service;

use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Entity\MarketplaceSale;
use App\Marketplace\Inventory\CostPriceResolverInterface;

/**
 * Резолвер себестоимости для документов маркетплейса.
 * Единственное место логики получения costPrice в процессорах.
 *
 * Зависит от CostPriceResolverInterface — не знает как считается себестоимость.
 * Передаёт listingId — себестоимость привязана к листингу, не к продукту.
 *
 * Публичные сигнатуры resolveForSale / resolveForReturn не изменились —
 * все процессоры (Ozon, WB) продолжают работать без изменений.
 */
final class MarketplaceCostPriceResolver
{
    public function __construct(
        private readonly CostPriceResolverInterface $costPriceResolver,
    ) {
    }

    /**
     * Получить себестоимость для продажи.
     */
    public function resolveForSale(MarketplaceListing $listing, \DateTimeImmutable $saleDate): string
    {
        return $this->resolve($listing, $saleDate);
    }

    /**
     * Получить себестоимость для возврата.
     *
     * Цепочка:
     * 1. Берём costPrice из связанной продажи если > 0
     * 2. Ищем по order_dt из rawData
     * 3. Резолвим по returnDate — дата возврата как fallback
     */
    public function resolveForReturn(
        MarketplaceListing $listing,
        ?MarketplaceSale $sale,
        ?array $rawData,
        ?\DateTimeImmutable $returnDate = null,
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

        // Шаг 3: резолвим по дате возврата если передана
        if ($returnDate !== null) {
            return $this->resolve($listing, $returnDate);
        }

        // Шаг 4: нет данных для определения
        return '0.00';
    }

    private function resolve(MarketplaceListing $listing, \DateTimeImmutable $date): string
    {
        return $this->costPriceResolver->resolve(
            companyId: (string) $listing->getCompany()->getId(),
            listingId: $listing->getId(),
            date:      $date,
        );
    }
}
