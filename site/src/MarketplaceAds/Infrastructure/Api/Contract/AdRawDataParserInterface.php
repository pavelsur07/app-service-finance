<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Infrastructure\Api\Contract;

use App\MarketplaceAds\Application\DTO\AdRawEntry;

interface AdRawDataParserInterface
{
    /**
     * Поддерживает ли парсер данный маркетплейс (значение MarketplaceType).
     */
    public function supports(string $marketplace): bool;

    /**
     * Распарсить raw-ответ площадки в унифицированные записи.
     * Записи с одинаковым (campaignId, parentSku) агрегируются: cost / impressions / clicks суммируются.
     *
     * @return AdRawEntry[]
     */
    public function parse(string $rawPayload): array;
}
