<?php

declare(strict_types=1);

namespace App\Marketplace\Message;

/**
 * Загрузить начисления Ozon за один день через /v1/finance/accrual/by-day.
 *
 * Пришло на смену SyncOzonReportMessage: Ozon снял /v3/finance/transaction/list
 * 09.09.2026, старая цепочка удалена 23.09.2026. 967 документов формата v3
 * переобрабатываются процессорами по сохранённому сырью, без загрузки.
 * Ставится только через OzonAccrualSyncPlanner.
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
