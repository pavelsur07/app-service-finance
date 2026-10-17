<?php

declare(strict_types=1);

namespace App\Marketplace\Enum;

/**
 * Вид строк продаж для покрытия правилами в предпросмотре ОПиУ.
 */
enum PlPreviewSalesKind: string
{
    case SALE = 'sale';
    case RETURN = 'return';
    case REALIZATION = 'realization';
    case REALIZATION_RETURN = 'realization_return';

    public function label(): string
    {
        return match ($this) {
            self::SALE => 'Продажи',
            self::RETURN => 'Возвраты',
            self::REALIZATION => 'Реализация Ozon',
            self::REALIZATION_RETURN => 'Возвраты по реализации Ozon',
        };
    }
}
