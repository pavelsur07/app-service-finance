<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Source\Wildberries;

final class WbResourceType
{
    public const FINANCE_SALES_REPORT_DETAILED = 'wildberries_finance_sales_report_detailed';

    /**
     * Заказы из marketplace-api: `/api/v3/orders` плюс `/api/v3/orders/status`.
     * Несёт состав заказа и оба статусных поля, но не знает об отменах,
     * пришедших позже.
     */
    public const ORDERS_MARKETPLACE = 'wildberries_orders_marketplace';

    /**
     * Заказы из statistics-api: `/api/v1/supplier/orders?flag=0`. Это поток
     * ИЗМЕНЕНИЙ по lastChangeDate, а не срез за период: он приносит отмены и
     * правки задним числом, которых в marketplace-потоке уже не увидеть.
     */
    public const ORDERS_STATISTICS = 'wildberries_orders_statistics';

    /**
     * Сырьё почасового перепроса статусов — только для аудита, маппера нет.
     */
    public const ORDER_STATUS_REFRESH = 'wildberries_order_status_refresh';
}
