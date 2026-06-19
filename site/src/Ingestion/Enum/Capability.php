<?php

declare(strict_types=1);

namespace App\Ingestion\Enum;

enum Capability: string
{
    case CAN_DISCOVER_SHOPS = 'can_discover_shops';
    case CAN_PULL = 'can_pull';
    case CAN_PUSH = 'can_push';

    public function label(): string
    {
        return match ($this) {
            self::CAN_DISCOVER_SHOPS => 'Обнаружение магазинов',
            self::CAN_PULL => 'Загрузка данных',
            self::CAN_PUSH => 'Отправка данных',
        };
    }
}
