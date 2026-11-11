<?php

declare(strict_types=1);

namespace App\Ingestion\Application\DTO;

/**
 * Строки отчётов «Оплаты за заказ» кабинета за период: по одной на (заказ, SKU, день), из последней
 * загруженной версии. reportsRead — прочитано сырых отчётов, unreadable — строк без даты или расхода.
 */
final readonly class OzonSearchPromoOrders
{
    /**
     * @param list<OzonSearchPromoOrderRow> $rows
     */
    public function __construct(
        public array $rows,
        public int $reportsRead,
        public int $unreadable,
    ) {
    }
}
