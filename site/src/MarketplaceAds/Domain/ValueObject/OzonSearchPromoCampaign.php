<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Domain\ValueObject;

use App\Marketplace\Enum\AdSpendKind;

/**
 * Служебная кампания «Оплата за заказ» Ozon: расход из отчёта Performance по заказам продвижения в
 * поиске. В отчёте нет id кампаний, поэтому все такие расходы дня — одна кампания `search-promo`;
 * по ней же статистика отличает оплату за заказ от оплаты за клики (AdSpendKind).
 */
final class OzonSearchPromoCampaign
{
    public const string CAMPAIGN_ID = 'search-promo';
    public const string CAMPAIGN_NAME = 'Оплата за заказ';
    /** Префикс source_key сырого документа дня: `ozon-search-promo:<Y-m-d>`. */
    public const string SOURCE_KEY_PREFIX = 'ozon-search-promo:';

    /**
     * SQL-условие на колонку campaign_id для вида расхода; null — без условия.
     */
    public static function campaignCondition(string $column, ?AdSpendKind $kind): ?string
    {
        return match ($kind) {
            null => null,
            AdSpendKind::PAY_PER_ORDER => sprintf("%s = '%s'", $column, self::CAMPAIGN_ID),
            AdSpendKind::CLICKS => sprintf("%s <> '%s'", $column, self::CAMPAIGN_ID),
        };
    }
}
