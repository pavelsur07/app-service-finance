<?php

declare(strict_types=1);

namespace App\Marketplace\Enum;

/**
 * Статус processing run в daily raw pipeline.
 */
enum PipelineStatus: string
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
        return $this === self::COMPLETED || $this === self::FAILED;
    }

    public function isRunning(): bool
    {
        return $this === self::RUNNING;
    }
}
