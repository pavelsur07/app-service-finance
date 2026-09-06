<?php

declare(strict_types=1);

namespace App\Inventory\Facade;

use App\Inventory\Application\DTO\StockOnDateResult;
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
     * Возвращает остатки (шт) на дату отчёта по листингам вместе с происхождением данных.
     *
     * Правило выбора snapshot:
     * 1) Если есть snapshot точно на дату отчёта — берётся он.
     * 2) Иначе берётся последний доступный snapshot с датой <= даты отчёта.
     * 3) Если такой snapshot старше порога свежести — он не используется, а его
     *    источник возвращается в staleSources. Остановившаяся загрузка не должна
     *    выглядеть здоровыми данными.
     * 4) Если snapshot до даты отчёта отсутствует — возвращается пустой результат.
     *
     * Отсутствие листинга в qtyByListingId означает «остаток неизвестен», а не «ноль».
     */
    public function getStockQtyByListingOnReportDate(string $companyId, \DateTimeImmutable $reportDate): StockOnDateResult
    {
        return $this->stockQtyByListingOnDateQuery->execute($companyId, $reportDate);
    }
}
