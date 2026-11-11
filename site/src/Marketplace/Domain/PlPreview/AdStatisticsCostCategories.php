<?php

declare(strict_types=1);

namespace App\Marketplace\Domain\PlPreview;

use App\Marketplace\Enum\AdSpendKind;
use App\Marketplace\Enum\MarketplaceType;

/**
 * Учётные категории затрат, которые покрывает рекламная статистика (модуль MarketplaceAds):
 * у них в предпросмотре ОПиУ колонки тегов строятся по статистике кампаний, а не по учёту, где
 * реклама лежит суммой на кабинет без товара.
 *
 * Список — свойство источника данных, а не настройка компании: рекламные API отдают оплату за клик
 * Ozon (Performance), оплату за заказ Ozon (отчёт по заказам продвижения в поиске) и продвижение WB
 * (/adv/v1/upd). Категория, которой в статистике нет, здесь обнулила бы свои суммы на тегах.
 * Решения Владельца 17.10.2026 и 11.11.2026.
 */
final class AdStatisticsCostCategories
{
    // Категория → вид статистики, которым она закрывается; null — вся статистика маркетплейса.
    private const array LEDGER = [
        'ozon' => ['ozon_cpc' => AdSpendKind::CLICKS, self::PAY_PER_ORDER_CODE => AdSpendKind::PAY_PER_ORDER],
        'wildberries' => ['wb_okazanie_uslug_wb_prodvizhenie' => null],
    ];

    // Оплата за заказ (тип начисления Ozon 54, «Маркетинговые акции»). Отчёт по заказам покрывает её
    // не целиком: у части кабинетов в тот же тип идут другие списания (сверка 11.11.2026), поэтому
    // в codes() её нет — отчёты по листингам вычитают из неё статистику, а не исключают целиком.
    private const string PAY_PER_ORDER_CODE = 'ozon_marketing_action';

    // Предоплата рекламного баланса (тип начисления Ozon `Marketing`, «Рекламные услуги»):
    // крупная сумма в начале месяца, из которой Ozon списывает клики и заказы; дневные `ozon_cpc`
    // приходят только когда предоплата кончилась (сверка на проде 09.11.2026: в день
    // окончания — часть дневной статистики, дальше дни сходятся до рубля). Расход периода —
    // статистика, поэтому в отчётах, где реклама из статистики, эта категория посчитала бы его
    // второй раз. В ledgerCategories() её нет: разносить по тегам предоплату не на чем.
    private const array PREPAYMENT_CODES = ['ozon_marketing_services'];

    /**
     * Категории маркетплейса, которые предпросмотр ОПиУ берёт из статистики, и вид статистики каждой.
     *
     * @return array<string, AdSpendKind|null> код категории → вид (null — вся статистика)
     */
    public static function ledgerCategories(MarketplaceType $marketplace): array
    {
        return self::LEDGER[$marketplace->value] ?? [];
    }

    /**
     * Категории, расход по которым целиком есть в рекламной статистике: отчёты по листингам их
     * учётную сумму не показывают.
     *
     * @return list<string>
     */
    public static function codes(): array
    {
        return ['ozon_cpc', 'wb_okazanie_uslug_wb_prodvizhenie', ...self::PREPAYMENT_CODES];
    }

    /**
     * Все категории под статистикой, включая покрытые частично (оплата за заказ).
     *
     * @return list<string>
     */
    public static function allCodes(): array
    {
        return [...self::codes(), self::PAY_PER_ORDER_CODE];
    }

    /**
     * Категория оплаты за заказ: в отчётах по листингам от её учётной суммы остаётся только часть,
     * которой нет в статистике.
     */
    public static function payPerOrderCode(): string
    {
        return self::PAY_PER_ORDER_CODE;
    }
}
