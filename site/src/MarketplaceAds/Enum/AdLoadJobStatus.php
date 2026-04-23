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
    /**
     * Частичный успех: часть батчей job'а завершилась OK, часть — FAILED/ABANDONED.
     * Выставляется финализатором cron-driven pipeline (Task-11.7) через
     * {@see \App\MarketplaceAds\Repository\AdLoadJobRepository::markPartialSuccess()}.
     * Старый Messenger-pipeline (`AdLoadJobFinalizer`) этот статус не использует.
     */
    case PARTIAL_SUCCESS = 'partial_success';

    public function getLabel(): string
    {
        return match ($this) {
            self::PENDING => 'Ожидает',
            self::RUNNING => 'Выполняется',
            self::COMPLETED => 'Завершён',
            self::FAILED => 'Ошибка',
            self::PARTIAL_SUCCESS => 'Частично завершён',
        };
    }

    public function isTerminal(): bool
    {
        return self::COMPLETED === $this
            || self::FAILED === $this
            || self::PARTIAL_SUCCESS === $this;
    }

    public function isActive(): bool
    {
        return self::PENDING === $this || self::RUNNING === $this;
    }
}
