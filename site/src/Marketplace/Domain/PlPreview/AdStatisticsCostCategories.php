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
    // Одна категория на маркетплейс: статистика — одна сумма на листинг, делить её между
    // несколькими учётными категориями не на чем.
    private const array CODES = [
        'ozon' => 'ozon_cpc',
        'wildberries' => 'wb_okazanie_uslug_wb_prodvizhenie',
    ];

    // Предоплата рекламного баланса (тип начисления Ozon `Marketing`, «Рекламные услуги»):
    // крупная сумма в начале месяца, из которой Ozon списывает клики; дневные `ozon_cpc`
    // приходят только когда предоплата кончилась (сверка на проде 09.11.2026: в день
    // окончания — часть дневной статистики, дальше дни сходятся до рубля). Расход периода —
    // статистика, поэтому в отчётах, где реклама из статистики, эта категория посчитала бы
    // его второй раз.
    // В codeFor() её нет: разносить по тегам предоплату не на чем.
    private const array PREPAYMENT_CODES = ['ozon_marketing_services'];

    public static function codeFor(MarketplaceType $marketplace): ?string
    {
        return self::CODES[$marketplace->value] ?? null;
    }

    /**
     * Все учётные категории, расход по которым уже есть в рекламной статистике.
     *
     * @return list<string>
     */
    public static function codes(): array
    {
        return [...array_values(self::CODES), ...self::PREPAYMENT_CODES];
    }
}
