<?php

namespace App\Marketplace\Message;

/**
 * Сообщение для асинхронной синхронизации отчёта WB по реализации.
 * Отправляется из Console Command, обрабатывается Worker'ом.
 */
final class SyncWbReportMessage
{
    public function __construct(
        public readonly string $companyId,       // ✅ scalar string (worker-safe)
        public readonly string $connectionId,    // ✅ scalar string (worker-safe)
    ) {}
}
