<?php

declare(strict_types=1);

namespace App\Marketplace\Domain\PlPreview;

use App\Marketplace\Enum\MarketplaceType;

/**
 * Учётные категории затрат, которые покрывает рекламная статистика (модуль MarketplaceAds):
 * у них в предпросмотре ОПиУ колонки тегов строятся по статистике кампаний, а не по учёту, где
 * реклама лежит суммой на кабинет без товара.
 *
 * Список — свойство источника данных, а не настройка компании: рекламные API отдают оплату за клик
 * Ozon (Performance) и продвижение WB (/adv/v1/upd). Категория, которой в статистике нет, здесь
 * обнулила бы свои суммы на тегах. Решение Владельца 17.10.2026.
 */
final class AdStatisticsCostCategories
{
    private const array CODES = [
        'ozon' => ['ozon_cpc'],
        'wildberries' => ['wb_okazanie_uslug_wb_prodvizhenie'],
    ];

    /**
     * @return list<string>
     */
    public static function codesFor(MarketplaceType $marketplace): array
    {
        return self::CODES[$marketplace->value] ?? [];
    }

    public static function covers(MarketplaceType $marketplace, string $costCategoryCode): bool
    {
        return \in_array($costCategoryCode, self::codesFor($marketplace), true);
    }
}
