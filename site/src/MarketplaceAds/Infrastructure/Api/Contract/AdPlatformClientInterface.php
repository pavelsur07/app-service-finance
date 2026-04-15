<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Infrastructure\Api\Contract;

use App\Marketplace\Enum\MarketplaceConnectionType;

interface AdPlatformClientInterface
{
    /**
     * Поддерживает ли клиент данный маркетплейс (значение MarketplaceType).
     */
    public function supports(string $marketplace): bool;

    /**
     * Тип MarketplaceConnection, в котором лежат credentials для этого клиента.
     *
     * Ozon Performance API использует отдельное подключение
     * ({@see MarketplaceConnectionType::PERFORMANCE}); WB Advert API живёт
     * на основном Seller-токене ({@see MarketplaceConnectionType::SELLER}).
     */
    public function getRequiredConnectionType(): MarketplaceConnectionType;

    /**
     * Загрузить рекламную статистику за дату.
     *
     * @return string сырой JSON-ответ API
     *
     * @throws \RuntimeException если API недоступен или вернул ошибку
     */
    public function fetchAdStatistics(string $companyId, \DateTimeImmutable $date): string;
}
