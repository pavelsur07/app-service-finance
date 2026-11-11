<?php

declare(strict_types=1);

namespace App\Marketplace\Enum;

/**
 * Вид рекламного расхода в статистике: оплата за клики (кампании CPC) или за заказ (продвижение в
 * поиске Ozon). Разные учётные категории маркетплейса закрываются разными видами статистики.
 */
enum AdSpendKind: string
{
    case CLICKS = 'clicks';
    case PAY_PER_ORDER = 'pay_per_order';

    public function label(): string
    {
        return match ($this) {
            self::CLICKS => 'оплата за клики',
            self::PAY_PER_ORDER => 'оплата за заказ',
        };
    }
}
