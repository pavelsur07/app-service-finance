<?php

declare(strict_types=1);

namespace App\Marketplace\Enum;

enum FinancialReportSyncStatus: string
{
    case QUEUED = 'queued';
    case LOADING = 'loading';
    case RAW_LOADED = 'raw_loaded';
    case PROCESSING = 'processing';
    case SUCCESS = 'success';
    case EMPTY = 'empty';
    case FAILED = 'failed';
    case FAILED_FINAL = 'failed_final';
    case AUTH_FAILED = 'auth_failed';
    case CONFLICT = 'conflict';

    /**
     * Данные дня обработаны полностью, дальнейшей работы не требуется.
     *
     * EMPTY — корректно полученный пустой отчёт (день без операций): missing-планирование
     * его не перезапрашивает, а ошибка загрузки даёт FAILED/FAILED_FINAL, а не EMPTY.
     *
     * Это НЕ canRetry(): retryable-состояние (FAILED) для закрытия месяца не готово.
     * Match без default: новый case enum обязан получить явное решение.
     */
    public function isReadyForMonthClose(): bool
    {
        return match ($this) {
            self::SUCCESS, self::EMPTY => true,
            self::QUEUED, self::LOADING, self::RAW_LOADED, self::PROCESSING,
            self::FAILED, self::FAILED_FINAL, self::AUTH_FAILED, self::CONFLICT => false,
        };
    }

    /**
     * Обработка дня ещё идёт: закрытие не должно гоняться с ней.
     */
    public function isInProgress(): bool
    {
        return match ($this) {
            self::QUEUED, self::LOADING, self::RAW_LOADED, self::PROCESSING => true,
            self::SUCCESS, self::EMPTY,
            self::FAILED, self::FAILED_FINAL, self::AUTH_FAILED, self::CONFLICT => false,
        };
    }

    /**
     * Ошибка или несогласованное терминальное состояние: полноту данных не гарантирует.
     */
    public function isFailedOrInconsistent(): bool
    {
        return match ($this) {
            self::FAILED, self::FAILED_FINAL, self::AUTH_FAILED, self::CONFLICT => true,
            self::SUCCESS, self::EMPTY,
            self::QUEUED, self::LOADING, self::RAW_LOADED, self::PROCESSING => false,
        };
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::QUEUED => 'В очереди',
            self::LOADING => 'Загрузка',
            self::RAW_LOADED => 'Raw загружен',
            self::PROCESSING => 'Обработка',
            self::SUCCESS => 'Успешно',
            self::EMPTY => 'Нет данных',
            self::FAILED => 'Ошибка',
            self::FAILED_FINAL => 'Финальная ошибка',
            self::AUTH_FAILED => 'Ошибка авторизации',
            self::CONFLICT => 'Конфликт',
        };
    }
}
