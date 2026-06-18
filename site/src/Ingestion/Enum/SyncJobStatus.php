<?php

declare(strict_types=1);

namespace App\Ingestion\Enum;

enum SyncJobStatus: string
{
    case OPEN = 'open';
    case RUNNING = 'running';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::OPEN => 'Создан',
            self::RUNNING => 'Выполняется',
            self::COMPLETED => 'Завершён',
            self::FAILED => 'Ошибка',
            self::CANCELLED => 'Отменён',
        };
    }

    public function isTerminal(): bool
    {
        return self::COMPLETED === $this
            || self::FAILED === $this
            || self::CANCELLED === $this;
    }

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::OPEN => self::RUNNING === $next || self::CANCELLED === $next,
            self::RUNNING => self::RUNNING === $next
                || self::COMPLETED === $next
                || self::FAILED === $next
                || self::CANCELLED === $next,
            self::COMPLETED, self::FAILED, self::CANCELLED => false,
        };
    }
}
