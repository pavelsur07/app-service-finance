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

    /**
     * Справочник услуг: `type_id` -> имя. Начисления несут только идентификатор,
     * а разбор услуги в категорию затрат идёт по имени, поэтому справочник
     * забирается вместе с днём и хранится в том же документе.
     *
     * @return array<string, string>
     *
     * @throws MarketplaceApiException
     */
    public function fetchServiceTypes(string $companyId): array;
}
