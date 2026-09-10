<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Api\Ozon;

use App\Marketplace\Exception\MarketplaceApiException;

/**
 * Контракт загрузки начислений Ozon за день.
 *
 * Отдельный интерфейс — по образцу `OzonAccrualClientInterface` в Ingestion:
 * обработчику нужен подменяемый контракт, а реализация остаётся final.
 */
interface OzonAccrualByDayClientInterface
{
    /**
     * @return list<array<string, mixed>>
     *
     * @throws MarketplaceApiException
     */
    public function fetchDay(string $companyId, \DateTimeImmutable $date): array;
}
