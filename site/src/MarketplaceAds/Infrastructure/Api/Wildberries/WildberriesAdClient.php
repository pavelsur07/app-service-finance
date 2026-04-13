<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Infrastructure\Api\Wildberries;

use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceAds\Infrastructure\Api\Contract\AdPlatformClientInterface;

/**
 * Клиент Wildberries Advert API для загрузки рекламной статистики.
 *
 * TODO: реализовать вызов API.
 * Предполагаемые эндпоинты:
 *   GET  https://advert-api.wildberries.ru/adv/v1/promotion/count       — список активных кампаний
 *   POST https://advert-api.wildberries.ru/adv/v2/fullstats             — полная статистика за период (batch)
 * Документация: https://openapi.wildberries.ru/ (раздел «Продвижение»)
 *
 * Авторизация: Bearer token из WB-личного кабинета, scope «Продвижение».
 */
final class WildberriesAdClient implements AdPlatformClientInterface
{
    public function supports(string $marketplace): bool
    {
        return $marketplace === MarketplaceType::WILDBERRIES->value;
    }

    public function fetchAdStatistics(string $companyId, \DateTimeImmutable $date): string
    {
        // TODO: реализовать загрузку рекламной статистики Wildberries
        //   1. Получить WB API-токен с scope «Продвижение» для $companyId
        //   2. GET /adv/v1/promotion/count → получить список активных advertId за $date
        //   3. POST /adv/v2/fullstats с телом [{"id": advertId, "interval": {"begin": date, "end": date}}]
        //      (rate limit: до 300 запросов/мин, batch до 100 advertId)
        //   4. Объединить ответы в один JSON и вернуть
        throw new \RuntimeException('WildberriesAdClient::fetchAdStatistics is not implemented yet.');
    }
}
