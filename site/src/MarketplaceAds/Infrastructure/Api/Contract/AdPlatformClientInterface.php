<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Infrastructure\Api\Contract;

interface AdPlatformClientInterface
{
    /**
     * Поддерживает ли клиент данный маркетплейс (значение MarketplaceType).
     */
    public function supports(string $marketplace): bool;

    /**
     * Загрузить рекламную статистику за дату.
     *
     * @return string сырой JSON-ответ API
     *
     * @throws \RuntimeException если API недоступен или вернул ошибку
     */
    public function fetchAdStatistics(string $companyId, \DateTimeImmutable $date): string;
}
