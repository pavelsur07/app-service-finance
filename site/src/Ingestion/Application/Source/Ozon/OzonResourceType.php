<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Source\Ozon;

final class OzonResourceType
{
    public const ACCRUAL_POSTINGS = 'ozon_finance_accrual_postings';
    public const ACCRUAL_BY_DAY = 'ozon_finance_accrual_by_day';
    public const ACCRUAL_TYPES = 'ozon_finance_accrual_types';

    public const PERFORMANCE_CAMPAIGNS = 'ozon_performance_campaigns';
    public const PERFORMANCE_SKU_CAMPAIGN_OBJECTS = 'ozon_performance_sku_campaign_objects';
    public const PERFORMANCE_SEARCH_PROMO_PRODUCTS = 'ozon_performance_search_promo_products';
    public const PERFORMANCE_SKU_PRODUCT_STATISTICS = 'ozon_performance_sku_product_statistics';
    public const PERFORMANCE_SEARCH_PROMO_STATISTICS = 'ozon_performance_search_promo_statistics';
    public const PERFORMANCE_EXPENSE_STATISTICS = 'ozon_performance_expense_statistics';
}
