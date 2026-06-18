<?php

declare(strict_types=1);

namespace App\Ingestion\Enum;

enum PLDirtyPeriodReason: string
{
    case INGEST = 'ingest';
    case MANUAL = 'manual';
    case REMAP = 'remap';
    case MONTH_CHANGE = 'month_change';

    public function label(): string
    {
        return match ($this) {
            self::INGEST => 'Новые данные источника',
            self::MANUAL => 'Ручной запуск',
            self::REMAP => 'Изменение маппинга',
            self::MONTH_CHANGE => 'Смена периода операции',
        };
    }
}
