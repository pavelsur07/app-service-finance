<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Source\Ozon;

final class OzonResourceType
{
    public const DAILY_REPORT = 'ozon_seller_daily_report';
    public const REALIZATION = 'ozon_seller_realization';
    public const ACCRUAL_POSTINGS = 'ozon_finance_accrual_postings';
    public const ACCRUAL_BY_DAY = 'ozon_finance_accrual_by_day';
    public const ACCRUAL_TYPES = 'ozon_finance_accrual_types';
}
