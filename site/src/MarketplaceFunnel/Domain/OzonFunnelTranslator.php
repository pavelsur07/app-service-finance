<?php

declare(strict_types=1);

namespace App\MarketplaceFunnel\Domain;

/**
 * Строка SKU дня из фактов `ozon.analytics.funnel` (`{"dimensions","metrics"}`, ozon-fetcher) → строка воронки.
 * Метрики — по порядку запроса сервиса (`ozon.FunnelMetrics`); другое число метрик — день не переводится.
 */
final class OzonFunnelTranslator
{
    public const RESOURCE = 'ozon.analytics.funnel';
    public const CHANNEL = 'ozon';
    /** Запись дня, который Ozon не отдал по тарифу кабинета. */
    public const UNAVAILABLE_KEY = 'ozon:analytics.funnel:unavailable';

    private const METRIC_COUNT = 14;
    /** колонка → позиция метрики: hits_view, hits_view_search, hits_view_pdp, hits_tocart, ordered_units, revenue, delivered_units, cancellations, returns */
    private const POSITIONS = ['impressions' => 2, 'impressions_search' => 0, 'card_views' => 1, 'cart_adds' => 8, 'orders' => 9, 'revenue_minor' => 10, 'buyouts' => 13, 'cancels' => 12, 'returns' => 11];

    /**
     * @param array<string, mixed> $payload
     */
    public static function translate(array $payload): FunnelRow
    {
        $dimensions = $payload['dimensions'] ?? null;
        $first = \is_array($dimensions) ? ($dimensions[0] ?? null) : null;
        $sku = \is_array($first) ? ($first['id'] ?? null) : null;
        $metrics = $payload['metrics'] ?? null;
        if (!\is_string($sku) || '' === $sku || !\is_array($metrics) || self::METRIC_COUNT !== \count($metrics)) {
            throw new \DomainException('ozon funnel row has no sku or another metric count');
        }

        $values = [];
        foreach (self::POSITIONS as $column => $position) {
            $values[$column] = 'revenue_minor' === $column ? FunnelRow::minor($metrics[$position] ?? null) : FunnelRow::count($metrics[$position] ?? null);
        }

        return new FunnelRow($sku, $values);
    }
}
