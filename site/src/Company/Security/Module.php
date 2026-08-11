<?php

declare(strict_types=1);

namespace App\Company\Security;

enum Module: string
{
    case FINANCE = 'finance';
    case MARKETPLACE = 'marketplace';
    case DEALS = 'deals';
    case CATALOG = 'catalog';
    case ADMIN = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::FINANCE => 'Деньги и отчёты',
            self::MARKETPLACE => 'Маркетплейсы',
            self::DEALS => 'Сделки',
            self::CATALOG => 'Каталог',
            self::ADMIN => 'Администрирование',
        };
    }
}
