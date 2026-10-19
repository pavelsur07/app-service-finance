<?php

declare(strict_types=1);

namespace App\Ingestion\Enum;

/**
 * `confirmed` — отчёт кабинета однозначно связал тип начисления с группой Ozon;
 * `conflict` — голосов мало или они разошлись, группа не применяется.
 */
enum OzonAccrualTypeGroupStatus: string
{
    case CONFIRMED = 'confirmed';
    case CONFLICT = 'conflict';

    public function label(): string
    {
        return match ($this) {
            self::CONFIRMED => 'Подтверждена отчётом',
            self::CONFLICT => 'Не определена',
        };
    }
}
