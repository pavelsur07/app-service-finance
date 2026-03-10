<?php

declare(strict_types=1);

namespace App\Marketplace\Message;

/**
 * Сообщение для асинхронной загрузки сырых данных Ozon.
 * Отправляется из Console Command, обрабатывается Worker'ом.
 * Только загрузка — без процессинга продаж/возвратов.
 */
final class SyncOzonReportMessage
{
    public function __construct(
        public readonly string $companyId,    // ✅ scalar string (worker-safe)
        public readonly string $connectionId, // ✅ scalar string (worker-safe)
    ) {
    }
}
