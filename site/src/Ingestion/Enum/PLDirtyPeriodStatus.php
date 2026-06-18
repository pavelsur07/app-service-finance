<?php

declare(strict_types=1);

namespace App\Ingestion\Enum;

enum PLDirtyPeriodStatus: string
{
    case PENDING = 'pending';
    case REBUILDING = 'rebuilding';
    case DONE = 'done';
    case FAILED = 'failed';
    case BLOCKED_BY_CLOSE = 'blocked_by_close';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Ожидает перегенерации',
            self::REBUILDING => 'Перегенерируется',
            self::DONE => 'Готово',
            self::FAILED => 'Ошибка',
            self::BLOCKED_BY_CLOSE => 'Заблокировано закрытием',
        };
    }

    public function isTerminal(): bool
    {
        return self::DONE === $this
            || self::FAILED === $this
            || self::BLOCKED_BY_CLOSE === $this;
    }

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::PENDING => self::REBUILDING === $next || self::BLOCKED_BY_CLOSE === $next,
            self::REBUILDING => self::DONE === $next
                || self::FAILED === $next
                || self::BLOCKED_BY_CLOSE === $next,
            self::DONE, self::FAILED, self::BLOCKED_BY_CLOSE => self::PENDING === $next,
        };
    }
}
