<?php

declare(strict_types=1);

namespace App\Inventory\Facade;

use App\Inventory\Infrastructure\Query\StockQtyByListingOnDateQuery;

/**
 * Публичный API модуля Inventory для кросс-модульных чтений.
 */
final readonly class InventoryFacade
{
    public function __construct(private StockQtyByListingOnDateQuery $stockQtyByListingOnDateQuery)
    {
    }

    /**
     * Возвращает остатки (шт) на дату отчёта по листингам.
     *
     * Правило выбора snapshot:
     * 1) Если есть snapshot точно на дату отчёта — берётся он.
     * 2) Иначе берётся последний доступный snapshot с датой <= даты отчёта.
     * 3) Если snapshot до даты отчёта отсутствует — возвращается пустой набор.
     *
     * @return array<string, float> listingId => stockQty
     */
    public function getStockQtyByListingOnReportDate(string $companyId, \DateTimeImmutable $reportDate): array
    {
        return $this->stockQtyByListingOnDateQuery->execute($companyId, $reportDate);
    }
}
