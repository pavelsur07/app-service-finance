<?php

declare(strict_types=1);

namespace App\Marketplace\Inventory\Application;

use App\Marketplace\Inventory\CostPriceResolverInterface;
use App\Marketplace\Inventory\Infrastructure\Repository\MarketplaceInventoryCostPriceRepository;

/**
 * Реализация CostPriceResolverInterface для этапа 1.
 *
 * Ищет актуальную запись в marketplace_inventory_cost_prices по listingId на дату.
 * Если записи нет — возвращает '0.00' (контрольный ноль для закрытия месяца).
 *
 * На этапе 2 заменяется партионной реализацией без изменения потребителей.
 */
final class InventoryCostPriceResolver implements CostPriceResolverInterface
{
    public function __construct(
        private readonly MarketplaceInventoryCostPriceRepository $repository,
    ) {
    }

    public function resolve(
        string $companyId,
        string $listingId,
        \DateTimeImmutable $date,
    ): string {
        $record = $this->repository->findActiveAtDate($companyId, $listingId, $date);

        if ($record === null) {
            return '0.00';
        }

        return $record->getPriceAmount();
    }
}
