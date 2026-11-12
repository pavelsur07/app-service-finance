<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Domain\ValueObject;

use App\Marketplace\Enum\AdSpendKind;

/**
 * Служебные кампании «Оплаты за заказ» Ozon в рекламной статистике:
 * - `search-promo` — расход из отчёта Performance по заказам продвижения в поиске, точно по SKU;
 * - `all-sku-promo` — расход кампаний ALL_SKU_PROMO (есть только по кампании за день), распределённый
 *   по товарам кампании пропорционально продажам.
 * По ним статистика отличает оплату за заказ от оплаты за клики (AdSpendKind).
 */
final class OzonSearchPromoCampaign
{
    public const string CAMPAIGN_ID = 'search-promo';
    public const string CAMPAIGN_NAME = 'Оплата за заказ';
    /** Префикс source_key сырого документа дня: `ozon-search-promo:<Y-m-d>`. */
    public const string SOURCE_KEY_PREFIX = 'ozon-search-promo:';

    public const string ALL_SKU_CAMPAIGN_ID = 'all-sku-promo';
    public const string ALL_SKU_CAMPAIGN_NAME = 'Оплата за заказ (все товары)';
    /**
     * `ozon-search-promo:all-sku:<Y-m-d>` — внутри SOURCE_KEY_PREFIX: CPC-конвейер исключает оба
     * вида по одному префиксу (AdRawDocumentRepository).
     */
    public const string ALL_SKU_SOURCE_KEY_PREFIX = self::SOURCE_KEY_PREFIX.'all-sku:';

    public static function isPayPerOrder(string $campaignId): bool
    {
        return self::CAMPAIGN_ID === $campaignId || self::ALL_SKU_CAMPAIGN_ID === $campaignId;
    }

    /**
     * SQL-условие на колонку campaign_id для вида расхода; null — без условия.
     */
    public static function campaignCondition(string $column, ?AdSpendKind $kind): ?string
    {
        return match ($kind) {
            null => null,
            AdSpendKind::PAY_PER_ORDER => sprintf("%s IN ('%s', '%s')", $column, self::CAMPAIGN_ID, self::ALL_SKU_CAMPAIGN_ID),
            AdSpendKind::CLICKS => sprintf("%s NOT IN ('%s', '%s')", $column, self::CAMPAIGN_ID, self::ALL_SKU_CAMPAIGN_ID),
        };
    }
}
