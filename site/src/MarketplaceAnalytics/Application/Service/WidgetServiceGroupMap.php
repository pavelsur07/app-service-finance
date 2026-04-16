<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Application\Service;

use App\Marketplace\Domain\OzonCostCategory;

/**
 * Тонкий адаптер над OzonCostCategory для обратной совместимости.
 *
 * Маппинг категорий затрат Ozon к widgetGroup для витрины аналитики.
 * Используется в WidgetSummaryQuery для агрегации затрат по 5 группам.
 *
 * Fallback для неизвестных кодов: 'Другие услуги и штрафы'.
 */
final class WidgetServiceGroupMap
{
    /**
     * @return array<string, string> category_code => widgetGroup
     */
    public static function getCategoryToWidgetGroup(): array
    {
        /** @var array<string, string>|null $cache */
        static $cache = null;

        if ($cache === null) {
            $cache = [];
            foreach (OzonCostCategory::all() as $c) {
                $cache[$c->code] = $c->widgetGroup;
            }
        }

        return $cache;
    }
}
