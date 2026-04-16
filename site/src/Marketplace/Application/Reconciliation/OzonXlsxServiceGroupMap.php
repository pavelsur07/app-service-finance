<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Reconciliation;

use App\Marketplace\Domain\OzonCostCategory;

/**
 * Тонкий адаптер над OzonCostCategory для обратной совместимости.
 *
 * Маппинг наших категорий затрат к группам xlsx «Детализации начислений» Ozon.
 * Используется ТОЛЬКО для сверки xlsx vs API данные.
 *
 * @see OzonCostCategory — единственный источник правды
 */
final class OzonXlsxServiceGroupMap
{
    /**
     * @return array<string, string> category_code => serviceGroup (название группы в xlsx)
     */
    public static function getCategoryToServiceGroup(): array
    {
        /** @var array<string, string>|null $cache */
        static $cache = null;

        if ($cache === null) {
            $cache = [];
            foreach (OzonCostCategory::all() as $c) {
                $cache[$c->code] = $c->xlsxGroup;
            }
        }

        return $cache;
    }
}
