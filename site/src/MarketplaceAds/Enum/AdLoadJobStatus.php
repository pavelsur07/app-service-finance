<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Enum;

/**
 * Статус задания на пакетную загрузку рекламных отчётов.
 */
enum AdLoadJobStatus: string
{
    case PENDING = 'pending';
    case RUNNING = 'running';
    case COMPLETED = 'completed';
    case FAILED = 'failed';

    public function getLabel(): string
    {
        return match ($this) {
            self::PENDING => 'Ожидает',
            self::RUNNING => 'Выполняется',
            self::COMPLETED => 'Завершён',
            self::FAILED => 'Ошибка',
        };
    }

    public function isTerminal(): bool
    {
        return self::COMPLETED === $this || self::FAILED === $this;
    }

    public function isActive(): bool
    {
        return self::PENDING === $this || self::RUNNING === $this;
    }
}
