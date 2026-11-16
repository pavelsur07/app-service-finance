<?php

declare(strict_types=1);

namespace App\MoySklad\Enum;

enum AutomaticMappingApplyStatus: string
{
    case ACCEPTED = 'accepted';
    case CONFLICT = 'conflict';
    case FAILED = 'failed';
    case NOT_PROCESSED = 'not_processed';

    public function label(): string
    {
        return match ($this) {
            self::ACCEPTED => 'Запрос принят', self::CONFLICT => 'Конфликт', self::FAILED => 'Ошибка', self::NOT_PROCESSED => 'Не обработан',
        };
    }
}
