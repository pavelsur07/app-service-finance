<?php

declare(strict_types=1);

namespace App\Marketplace\Message;

/**
 * Загрузить начисления Ozon за один день через /v1/finance/accrual/by-day.
 *
 * Пришло на смену SyncOzonReportMessage: Ozon снял /v3/finance/transaction/list
 * 09.09.2026. Старое сообщение и его обработчик остаются — 967 существующих
 * документов обязаны переобрабатываться.
 *
 * Транспорт: async_sync (внешний HTTP).
 */
final readonly class SyncOzonAccrualByDayMessage
{
    public function __construct(
        public string $companyId,
        public string $connectionId,
        /** Бизнес-день в формате Y-m-d, Europe/Moscow. */
        public string $date,
    ) {
    }
}
