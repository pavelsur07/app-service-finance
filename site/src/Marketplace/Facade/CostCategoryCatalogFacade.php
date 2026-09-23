<?php

declare(strict_types=1);

namespace App\Marketplace\Facade;

use App\Marketplace\Application\DTO\CostCategoryGroupsDTO;
use App\Marketplace\Domain\OzonCostCategory;
use App\Marketplace\Domain\WbCostCategory;

/**
 * Справочники категорий затрат Ozon и WB для других модулей
 * (MarketplaceAnalytics — группы виджетов, разбивки и юнит-экономики).
 *
 * Отдаёт данные, а не решения: правила группировки остаются у потребителя.
 */
final readonly class CostCategoryCatalogFacade
{
    /**
     * @return array<string, CostCategoryGroupsDTO> code => группы; при повторе кода — первое вхождение
     */
    public function ozonCostCategoryGroups(): array
    {
        $groups = [];
        foreach (OzonCostCategory::all() as $category) {
            $groups[$category->code] ??= new CostCategoryGroupsDTO($category->widgetGroup, $category->xlsxGroup, null);
        }

        return $groups;
    }

    /**
     * @return array<string, CostCategoryGroupsDTO> code => группы
     */
    public function wbCostCategoryGroups(): array
    {
        $groups = [];
        foreach (WbCostCategory::byCode() as $code => $category) {
            $groups[$code] = new CostCategoryGroupsDTO($category->widgetGroup, $category->breakdownGroup, $category->unitBucket);
        }

        return $groups;
    }
}
