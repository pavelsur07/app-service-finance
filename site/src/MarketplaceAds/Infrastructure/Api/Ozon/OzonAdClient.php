<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Infrastructure\Api\Ozon;

use App\Marketplace\Enum\MarketplaceConnectionType;
use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceAds\Infrastructure\Api\Contract\AdPlatformClientInterface;

/**
 * Клиент Ozon Performance API для загрузки рекламной статистики.
 *
 * TODO: реализовать вызов API.
 * Предполагаемые эндпоинты:
 *   POST https://api-performance.ozon.ru/api/client/token       — получение токена по client_id/client_secret
 *   POST https://api-performance.ozon.ru/api/client/statistics  — запрос отчёта за дату
 *   GET  https://api-performance.ozon.ru/api/client/statistics/{uuid}  — получение готового отчёта
 * Документация: https://docs.ozon.ru/api/performance/
 */
final class OzonAdClient implements AdPlatformClientInterface
{
    public function supports(string $marketplace): bool
    {
        return $marketplace === MarketplaceType::OZON->value;
    }

    public function getRequiredConnectionType(): MarketplaceConnectionType
    {
        return MarketplaceConnectionType::PERFORMANCE;
    }

    public function fetchAdStatistics(string $companyId, \DateTimeImmutable $date): string
    {
        // TODO: реализовать загрузку рекламной статистики Ozon
        //   1. Получить credentials Ozon Performance API для $companyId (из хранилища токенов)
        //   2. Получить access token (кэшировать до истечения срока)
        //   3. Запросить отчёт за $date (формат Y-m-d)
        //   4. Дождаться готовности (polling по uuid) либо использовать sync-эндпоинт
        //   5. Вернуть raw JSON ответа
        throw new \RuntimeException('OzonAdClient::fetchAdStatistics is not implemented yet.');
    }
}
