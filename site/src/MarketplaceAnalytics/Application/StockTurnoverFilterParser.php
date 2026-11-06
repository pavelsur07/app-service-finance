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
 * Параметры читаются из сырого массива: InputBag::get()/getInt()/all() бросают 400
 * на массиве вместо строки, строке вместо массива и нечисловом значении.
 */
final readonly class StockTurnoverFilterParser
{
    /** Номер страницы: нечисловое или < 1 → 1; выход за диапазон решает контроллер. */
    public static function page(Request $request): int
    {
        return max(1, filter_var(self::string($request, 'page'), \FILTER_VALIDATE_INT, \FILTER_NULL_ON_FAILURE) ?? 1);
    }

    public function parse(Request $request, string $companyId): StockTurnoverFilter
    {
        $today = new \DateTimeImmutable('today');

        $period = filter_var(self::string($request, 'period'), \FILTER_VALIDATE_INT, \FILTER_NULL_ON_FAILURE) ?? 30;
        $rawDate = self::string($request, 'date');
        // Только форма Y-m-d: createFromFormat() бросает ValueError на нулевом байте раньше любого fallback.
        $date = 1 === preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawDate) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $rawDate) : false;
        if (false === $date || $date->format('Y-m-d') !== $rawDate || $date > $today) {
            $date = $today;
        }

        $rawTags = $request->query->all()['tags'] ?? [];
        $tags = array_values(array_filter(
            is_array($rawTags) ? $rawTags : [$rawTags],
            static fn (mixed $id): bool => is_string($id) && Uuid::isValid($id),
        ));

        return new StockTurnoverFilter(
            companyId: $companyId,
            date: $date,
            periodDays: in_array($period, StockTurnoverFilter::PERIODS, true) ? $period : 30,
            source: MarketplaceType::tryFrom(self::string($request, 'source'))?->value,
            scheme: FulfillmentScheme::tryFrom(self::string($request, 'scheme')),
            bucket: TurnoverBucket::tryFrom(self::string($request, 'bucket')),
            tagIds: $tags,
        );
    }

    /** Скалярный параметр строкой; массив или отсутствие → ''. */
    private static function string(Request $request, string $key): string
    {
        $value = $request->query->all()[$key] ?? '';

        return is_scalar($value) ? (string) $value : '';
    }
}
