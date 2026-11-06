<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Application;

use App\Inventory\Enum\FulfillmentScheme;
use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceAnalytics\DTO\StockTurnoverFilter;
use App\MarketplaceAnalytics\Enum\TurnoverBucket;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Request;

/**
 * Разбор GET-параметров отчёта «Оборачиваемость»; общий для страницы и экспорта.
 * Невалидное значение не ошибка, а умолчание: период 30, все источники/схемы/корзины,
 * дата — сегодня (будущее тоже → сегодня), теги — только валидные UUID.
 */
final readonly class StockTurnoverFilterParser
{
    public function parse(Request $request, string $companyId): StockTurnoverFilter
    {
        $today = new \DateTimeImmutable('today');

        $period = $request->query->getInt('period', 30);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $request->query->get('date', ''));
        if (false === $date || $date->format('Y-m-d') !== $request->query->get('date') || $date > $today) {
            $date = $today;
        }

        $tags = array_values(array_filter(
            array_map(strval(...), $request->query->all('tags')),
            static fn (string $id): bool => Uuid::isValid($id),
        ));

        return new StockTurnoverFilter(
            companyId: $companyId,
            date: $date,
            periodDays: in_array($period, StockTurnoverFilter::PERIODS, true) ? $period : 30,
            source: MarketplaceType::tryFrom((string) $request->query->get('source', ''))?->value,
            scheme: FulfillmentScheme::tryFrom((string) $request->query->get('scheme', '')),
            bucket: TurnoverBucket::tryFrom((string) $request->query->get('bucket', '')),
            tagIds: $tags,
        );
    }
}
