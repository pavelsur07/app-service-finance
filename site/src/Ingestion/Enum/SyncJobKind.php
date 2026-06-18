<?php

declare(strict_types=1);

namespace App\Ingestion\Enum;

enum SyncJobKind: string
{
    case BACKFILL = 'backfill';
    case INCREMENTAL = 'incremental';
    case MANUAL = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::BACKFILL => 'Бэкфилл',
            self::INCREMENTAL => 'Инкремент',
            self::MANUAL => 'Ручной',
        };
    }
}
