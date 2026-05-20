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
