<?php

declare(strict_types=1);

namespace App\Marketplace\Enum;

enum FinancialReportSyncMode: string
{
    case INITIAL = 'initial';
    case DAILY = 'daily';
    case REFRESH_14D = 'refresh_14d';
    case MISSING = 'missing';
    case MANUAL = 'manual';

    public function getLabel(): string
    {
        return match ($this) {
            self::INITIAL => 'Первичная загрузка',
            self::DAILY => 'Ежедневная загрузка',
            self::REFRESH_14D => 'Обновление за 14 дней',
            self::MISSING => 'Дозагрузка пропусков',
            self::MANUAL => 'Ручной запуск',
        };
    }
}
