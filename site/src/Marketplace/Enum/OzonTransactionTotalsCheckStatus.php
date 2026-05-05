<?php

declare(strict_types=1);

namespace App\Marketplace\Enum;

enum OzonTransactionTotalsCheckStatus: string
{
    case OK = 'ok';
    case WARNING = 'warning';
    case FAILED = 'failed';
    case SKIPPED = 'skipped';

    public function getLabel(): string
    {
        return match ($this) {
            self::OK => 'Успешно',
            self::WARNING => 'Предупреждение',
            self::FAILED => 'Ошибка',
            self::SKIPPED => 'Пропущено',
        };
    }

    public function isSuccessful(): bool
    {
        return $this === self::OK;
    }

    public function isBlocking(): bool
    {
        return $this === self::FAILED;
    }
}
