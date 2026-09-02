<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Api\Wildberries;

interface WbOrdersClientInterface
{
    /**
     * Страница `/api/v3/orders` marketplace-api.
     *
     * @param int $next курсор постраничности WB; 0 — начало
     */
    public function fetchMarketplaceOrders(
        string $companyId,
        string $connectionRef,
        \DateTimeImmutable $since,
        int $limit,
        int $next,
    ): WbOrdersPage;

    /**
     * Статусы `/api/v3/orders/status` по номерам заказов marketplace-api.
     *
     * Возвращает страницу: пригодные строки отдельно от отбракованных.
     * Повреждённая СТРОКА не роняет ответ — ответ, как правило, покрывает всё
     * подключение, и одна вечно кривая строка блокировала бы обновление всех
     * остальных заказов. Нарушение формы всего ответа остаётся исключением.
     *
     * @param list<int> $orderIds
     */
    public function fetchMarketplaceStatuses(string $companyId, string $connectionRef, array $orderIds): WbOrderStatusPage;

    /**
     * Поток изменений `/api/v1/supplier/orders?flag=0` statistics-api.
     *
     * Постраничности у эндпоинта нет: он отдаёт всё, что изменилось начиная с
     * `$since`, одним ответом.
     */
    public function fetchStatisticsOrders(
        string $companyId,
        string $connectionRef,
        \DateTimeImmutable $since,
    ): WbOrdersPage;
}
