<?php

declare(strict_types=1);

namespace App\Marketplace\Enum;

/**
 * Какая пара источников сверяется. «Источник» — сторона Ozon, «цель» — то, что проверяем.
 *
 * Продажи и возвраты в учёте лежат в базе `sale_amount` (цена продавца), а «Реализация»
 * считает в базе `sale_price` (цена покупателя), поэтому напрямую они несопоставимы:
 * «Реализация» сверяется с сырьём by-day в одной базе, а сырьё — с учётом.
 */
enum OzonReconciliationCheck: string
{
    case REALIZATION_VS_RAW = 'realization_vs_raw';
    case RAW_VS_LEDGER = 'raw_vs_ledger';

    public function getLabel(): string
    {
        return match ($this) {
            self::REALIZATION_VS_RAW => 'Реализация ↔ сырые данные',
            self::RAW_VS_LEDGER => 'Сырые данные ↔ учёт',
        };
    }

    public function getSourceLabel(): string
    {
        return match ($this) {
            self::REALIZATION_VS_RAW => 'Реализация Ozon',
            self::RAW_VS_LEDGER => 'Сырые данные Ozon',
        };
    }

    public function getTargetLabel(): string
    {
        return match ($this) {
            self::REALIZATION_VS_RAW => 'Сырые данные Ozon',
            self::RAW_VS_LEDGER => 'Учёт в системе',
        };
    }
}
