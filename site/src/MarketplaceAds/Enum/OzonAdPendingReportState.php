<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Enum;

/**
 * Логические состояния записи {@see \App\MarketplaceAds\Entity\OzonAdPendingReport}.
 *
 * Реализовано как константный класс, а не PHP-enum: колонка state хранит строку
 * (VARCHAR), чтобы Ozon-специфичные или неожиданные значения, пришедшие из
 * GET /api/client/statistics/{uuid}, можно было залогировать в БД без миграции.
 *
 * Канонические значения:
 *  - REQUESTED    — POST /statistics прошёл, UUID выдан, polling ещё не начинался.
 *  - NOT_STARTED  — Ozon получил запрос, но отчёт ещё в очереди (проброс как есть).
 *  - IN_PROGRESS  — Ozon начал формировать отчёт (проброс как есть).
 *  - OK           — отчёт готов (Ozon OK/READY → нормализуем в OK).
 *  - ERROR        — Ozon вернул ERROR/CANCELLED/NOT_FOUND.
 *  - ABANDONED    — наш polling исчерпал POLL_MAX_ATTEMPTS, отчёт брошен.
 */
final class OzonAdPendingReportState
{
    public const REQUESTED = 'REQUESTED';
    public const NOT_STARTED = 'NOT_STARTED';
    public const IN_PROGRESS = 'IN_PROGRESS';
    public const OK = 'OK';
    public const ERROR = 'ERROR';
    public const ABANDONED = 'ABANDONED';

    /**
     * Терминальные состояния — запись финализирована, polling завершён.
     */
    public const TERMINAL_STATES = [
        self::OK,
        self::ERROR,
        self::ABANDONED,
    ];

    /**
     * In-flight состояния — отчёт ещё не завершён, handler может восстановить
     * polling. Используется {@see \App\MarketplaceAds\Repository\OzonAdPendingReportRepository::findInFlightByJob()}.
     */
    public const IN_FLIGHT_STATES = [
        self::REQUESTED,
        self::NOT_STARTED,
        self::IN_PROGRESS,
    ];

    private function __construct()
    {
    }

    public static function isTerminal(string $state): bool
    {
        return in_array($state, self::TERMINAL_STATES, true);
    }
}
