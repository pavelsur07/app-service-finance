<?php

namespace App\Enum;

enum DocumentType: string
{
    case DEAL_SALE = 'DEAL_SALE';
    case PAYROLL = 'PAYROLL';
    case TAXES = 'TAXES';
    case LOANS = 'LOANS';
    case OBLIGATIONS = 'OBLIGATIONS';
    case ASSETS = 'ASSETS';
    case CASH = 'CASH';
    case CASHFLOW_EXPENSE = 'CASHFLOW_EXPENSE';
    case OTHER = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::DEAL_SALE => '1. Сделка - продажа',
            self::PAYROLL => '2. Зарплата',
            self::TAXES => '3. Налоги',
            self::LOANS => '4. Кредиты',
            self::OBLIGATIONS => '5. Обязательства',
            self::ASSETS => '6. Имущество',
            self::CASH => '7. Касса',
            self::CASHFLOW_EXPENSE => '8. Расход из ДДС',
            self::OTHER => '9. Прочие',
        };
    }
}
