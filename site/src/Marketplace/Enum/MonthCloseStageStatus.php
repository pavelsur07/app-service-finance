<?php

declare(strict_types=1);

namespace App\Marketplace\Enum;

enum MonthCloseStageStatus: string
{
    case PENDING  = 'pending';
    case CLOSED   = 'closed';
    case REOPENED = 'reopened';

    public function getLabel(): string
    {
        return match ($this) {
            self::PENDING  => 'Не закрыт',
            self::CLOSED   => 'Закрыт',
            self::REOPENED => 'Переоткрыт',
        };
    }

    public function isClosed(): bool
    {
        return $this === self::CLOSED;
    }
}
