<?php

declare(strict_types=1);

namespace App\MarketplaceOperations\Enum;

/**
 * Вид канонической операции — общий для всех каналов. Код маркетплейса как есть хранится
 * рядом в `source_type` (сверка с кабинетом), вид — для экономики (`DATA.md` §9.1).
 *
 * Состав — таблица уровней этапа 1 (`stages/stage-1-category-levels.md`) и виды выручки
 * (`DATA.md` §9.2). Прямой вид без листинга витрина относит к общим затратам — это правило
 * экономики, не вида.
 *
 * ponytail: enum, а не таблица — новый вид редок; перевести в таблицу, когда новый вид
 * понадобится без деплоя (экран подтверждения, этап 4, `DATA.md` §9.4).
 */
enum OperationKind: string
{
    case SALE = 'sale';
    case RETURN = 'return';
    case SPP = 'spp';
    case COINVESTMENT = 'coinvestment';

    case COMMISSION = 'commission';
    case COMMISSION_BRAND = 'commission_brand';
    case LOGISTICS_TO = 'logistics_to';
    case LOGISTICS_TO_LAST_MILE = 'logistics_to_last_mile';
    case LOGISTICS_TO_DROPOFF = 'logistics_to_dropoff';
    case LOGISTICS_TO_HANDOVER = 'logistics_to_handover';
    case LOGISTICS_BACK = 'logistics_back';
    case LOGISTICS_BACK_PVZ = 'logistics_back_pvz';
    case ACQUIRING = 'acquiring';
    case PACKAGING_LABOR = 'packaging_labor';
    case PACKAGING_MATERIALS = 'packaging_materials';
    case STORAGE = 'storage';
    case STOCK_REMOVAL = 'stock_removal';
    case DISPOSAL = 'disposal';

    case ADS_CPC = 'ads_cpc';
    case ADS_PAY_PER_ORDER = 'ads_pay_per_order';
    case PROMO_STARS = 'promo_stars';

    case PACKAGING_WAREHOUSE = 'packaging_warehouse';
    case PROMO_PREMIUM = 'promo_premium';
    case PROMO_SELLER_BONUS = 'promo_seller_bonus';
    case PROMO_REVIEWS = 'promo_reviews';
    case PROMO_CHAT_POINTS = 'promo_chat_points';
    case SUBSCRIPTION = 'subscription';
    case FINE = 'fine';
    case SERVICE_CORRECTION = 'service_correction';
    /** Компенсация маркетплейса за утерянный или повреждённый товар — прочий доход (+). */
    case COMPENSATION = 'compensation';
    /** Прочие удержания маркетплейса без своего вида (пожертвования, разовые удержания). */
    case MP_OTHER_DEDUCTIONS = 'mp_other_deductions';

    public function level(): CascadeLevel
    {
        return match ($this) {
            self::SALE, self::RETURN => CascadeLevel::REVENUE,
            self::SPP, self::COINVESTMENT => CascadeLevel::INFO,
            self::ADS_CPC, self::ADS_PAY_PER_ORDER, self::PROMO_STARS => CascadeLevel::MD2,
            self::PACKAGING_WAREHOUSE, self::PROMO_PREMIUM, self::PROMO_SELLER_BONUS, self::PROMO_REVIEWS,
            self::PROMO_CHAT_POINTS, self::SUBSCRIPTION, self::FINE, self::SERVICE_CORRECTION, self::COMPENSATION, self::MP_OTHER_DEDUCTIONS => CascadeLevel::OVERHEAD,
            default => CascadeLevel::MD1,
        };
    }

    /** Название вида для экранов и черновика ИИ. */
    public function label(): string
    {
        return match ($this) {
            self::SALE => 'Продажа',
            self::RETURN => 'Возврат',
            self::SPP => 'Скидка за счёт маркетплейса (СПП)',
            self::COINVESTMENT => 'Соинвестирование',
            self::COMMISSION => 'Комиссия за продажу',
            self::COMMISSION_BRAND => 'Брендовая комиссия',
            self::LOGISTICS_TO => 'Логистика до покупателя',
            self::LOGISTICS_TO_LAST_MILE => 'Последняя миля',
            self::LOGISTICS_TO_DROPOFF => 'Обработка отправления (drop-off)',
            self::LOGISTICS_TO_HANDOVER => 'Доставка до места передачи',
            self::LOGISTICS_BACK => 'Обратная логистика',
            self::LOGISTICS_BACK_PVZ => 'Обработка возвратов в ПВЗ',
            self::ACQUIRING => 'Эквайринг',
            self::PACKAGING_LABOR => 'Упаковка партнёрами',
            self::PACKAGING_MATERIALS => 'Упаковочные материалы',
            self::STORAGE => 'Хранение',
            self::STOCK_REMOVAL => 'Вывоз остатков',
            self::DISPOSAL => 'Утилизация',
            self::ADS_CPC => 'Реклама: оплата за клик',
            self::ADS_PAY_PER_ORDER => 'Реклама: оплата за заказ',
            self::PROMO_STARS => 'Звёздные товары',
            self::PACKAGING_WAREHOUSE => 'Доп. упаковка на складе',
            self::PROMO_PREMIUM => 'Подписка Premium',
            self::PROMO_SELLER_BONUS => 'Бонусы продавца',
            self::PROMO_REVIEWS => 'Отзывы за баллы',
            self::PROMO_CHAT_POINTS => 'Баллы за чат',
            self::SUBSCRIPTION => 'Подписки и аналитика',
            self::FINE => 'Штрафы',
            self::SERVICE_CORRECTION => 'Корректировка услуг',
            self::COMPENSATION => 'Компенсации',
            self::MP_OTHER_DEDUCTIONS => 'Прочие удержания МП',
        };
    }
}
