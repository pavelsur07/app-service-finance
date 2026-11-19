<?php

declare(strict_types=1);

namespace App\MarketplaceFunnel\Domain;

/**
 * Карточка дня из фактов `wb.analytics.search` (`{"nmId", показатель: значение дня | null}`, wb-fetcher) →
 * строка воронки источника «поиск»: переходы в карточку, корзины и заказы из поиска. Видимость и средняя
 * позиция остаются в фактах — это не ступени воронки. Показателя нет — NULL.
 */
final class WbSearchTranslator
{
    public const RESOURCE = 'wb.analytics.search';
    public const CHANNEL = WbFunnelTranslator::CHANNEL;
    public const SOURCE = 'search';
    /** Запись дня, который WB не отдал по тарифу кабинета (нет подписки «Джем»). */
    public const UNAVAILABLE_KEY = 'wb:analytics.search:unavailable';

    private const FIELDS = ['card_views' => 'openCard', 'cart_adds' => 'addToCart', 'orders' => 'orders'];

    /**
     * @param array<string, mixed> $payload
     */
    public static function translate(array $payload): FunnelRow
    {
        $nmId = $payload['nmId'] ?? null;
        if (!\is_int($nmId) || $nmId <= 0) {
            throw new \DomainException('wb search card has no nmId');
        }
        $values = array_fill_keys(FunnelRow::COLUMNS, null);
        foreach (self::FIELDS as $column => $field) {
            if (null !== ($payload[$field] ?? null)) {
                $values[$column] = FunnelRow::count($payload[$field]);
            }
        }

        return new FunnelRow((string) $nmId, $values);
    }
}
