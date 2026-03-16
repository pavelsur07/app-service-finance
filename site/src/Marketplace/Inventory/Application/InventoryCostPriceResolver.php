<?php

declare(strict_types=1);

namespace App\Marketplace\Inventory\Application;

use App\Marketplace\Inventory\CostPriceResolverInterface;
use App\Marketplace\Inventory\Infrastructure\Repository\MarketplaceInventoryCostPriceRepository;

/**
 * Реализация CostPriceResolverInterface.
 *
 * Логика резолва на дату:
 *   1. Ищем запись где effectiveFrom <= date AND (effectiveTo IS NULL OR effectiveTo >= date)
 *   2. Если не найдена — дата раньше первой записи → возвращаем самую раннюю запись
 *      (себестоимость считается действующей с момента первой установки)
 *   3. Если записей нет вообще — возвращаем '0.00'
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
        // Шаг 1: ищем активную запись на дату
        $record = $this->repository->findActiveAtDate($companyId, $listingId, $date);

        if ($record !== null) {
            return $record->getPriceAmount();
        }

        // Шаг 2: дата раньше первой записи — берём самую раннюю
        $earliest = $this->repository->findEarliest($companyId, $listingId);

        if ($earliest !== null) {
            return $earliest->getPriceAmount();
        }

        // Шаг 3: записей нет вообще
        return '0.00';
    }
}
