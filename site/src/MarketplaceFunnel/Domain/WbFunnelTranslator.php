<?php

declare(strict_types=1);

namespace App\MarketplaceFunnel\Domain;

/**
 * Карточка дня из фактов `wb.analytics.funnel` (`{"product","statistic"}`, wb-fetcher) → строка воронки.
 * Общих показов и возвратов в воронке WB нет: первая ступень — переходы в карточку. Ступень без поля — NULL.
 */
final class WbFunnelTranslator
{
    public const RESOURCE = 'wb.analytics.funnel';
    public const CHANNEL = 'wildberries';

    private const FIELDS = ['card_views' => 'openCount', 'cart_adds' => 'cartCount', 'orders' => 'orderCount', 'revenue_minor' => 'orderSum', 'buyouts' => 'buyoutCount', 'cancels' => 'cancelCount'];

    /**
     * @param array<string, mixed> $payload
     */
    public static function translate(array $payload): FunnelRow
    {
        $product = $payload['product'] ?? null;
        $statistic = $payload['statistic'] ?? null;
        $nmId = \is_array($product) ? ($product['nmId'] ?? null) : null;
        $selected = \is_array($statistic) ? ($statistic['selected'] ?? null) : null;
        if (!\is_int($nmId) || $nmId <= 0 || !\is_array($selected)) {
            throw new \DomainException('wb funnel card has no nmId or no selected statistic');
        }

        $values = array_fill_keys(FunnelRow::COLUMNS, null);
        foreach (self::FIELDS as $column => $field) {
            if (null === ($selected[$field] ?? null)) {
                continue; // поля нет — «нет данных» по ступени, остальные ступени карточки переводятся
            }
            $values[$column] = 'revenue_minor' === $column ? FunnelRow::minor($selected[$field]) : FunnelRow::count($selected[$field]);
        }

        return new FunnelRow((string) $nmId, $values);
    }
}
