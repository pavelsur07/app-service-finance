<?php

declare(strict_types=1);

namespace App\Marketplace\Enum;

enum ReconciliationSessionStatus: string
{
    case PENDING   = 'pending';
    case COMPLETED = 'completed';
    case FAILED    = 'failed';

    public function getLabel(): string
    {
        return match ($this) {
            self::PENDING   => 'Ожидает',
            self::COMPLETED => 'Завершена',
            self::FAILED    => 'Ошибка',
        };
    }

    public function isPending(): bool
    {
        return $this === self::PENDING;
    }

    public function isTerminal(): bool
    {
        return $this === self::COMPLETED || $this === self::FAILED;
    }
}
