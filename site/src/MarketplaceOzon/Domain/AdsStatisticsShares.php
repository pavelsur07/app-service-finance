<?php

declare(strict_types=1);

namespace App\MarketplaceOzon\Domain;

/**
 * Доли рекламы дня по SKU из фактов `ozon.ads.statistics` (строка CSV Performance:
 * кампания × SKU, расход строкой «1 152,19»). Разносит CPC кабинета (by-day — без SKU)
 * по SKU методом наибольшего остатка; сумма не меняется.
 */
final class AdsStatisticsShares
{
    public const RESOURCE = 'ozon.ads.statistics';
    public const ALLOCATION_KEY = 'ads_statistics_share';

    private const SPEND_COLUMNS = ['расход, ₽, с ндс', 'расход, ₽', 'расход', 'spend', 'cost'];

    /**
     * Расход по SKU в копейках.
     *
     * @param iterable<array<string, mixed>> $payloads
     *
     * @return array<array-key, int> sku => копейки, только положительные (числовой SKU — int-ключ PHP)
     */
    public static function spendBySku(iterable $payloads): array
    {
        $spend = [];
        foreach ($payloads as $row) {
            $sku = trim((string) ($row['sku'] ?? ''));
            if ('' === $sku) {
                continue;
            }
            $value = null;
            foreach ($row as $column => $cell) {
                if (\in_array(mb_strtolower(trim((string) $column)), self::SPEND_COLUMNS, true)) {
                    $value = (string) $cell;
                    break;
                }
            }
            $normalized = str_replace([' ', "\u{00A0}", "\u{202F}", ','], ['', '', '', '.'], (string) $value);
            if (!is_numeric($normalized)) {
                continue;
            }
            $spend[$sku] = ($spend[$sku] ?? 0) + (int) round((float) $normalized * 100);
        }

        return array_filter($spend, static fn (int $minor): bool => $minor > 0);
    }

    /**
     * Делит сумму по весам; ключ `''` — часть, которая остаётся без SKU.
     *
     * @param array<string, int> $weights ключ => вес > 0
     *
     * @return array<string, int> ключ => копейки (Σ = $amountMinor), нулевые доли опущены
     */
    public static function split(int $amountMinor, array $weights): array
    {
        $total = array_sum($weights);
        if (0 === $amountMinor) {
            return [];
        }
        if ($total <= 0) {
            return ['' => $amountMinor];
        }
        $sign = $amountMinor < 0 ? -1 : 1;
        $abs = abs($amountMinor);
        ksort($weights, \SORT_STRING);
        $parts = $remainders = [];
        foreach ($weights as $key => $weight) {
            $exact = $abs * $weight;
            $parts[$key] = intdiv($exact, $total);
            $remainders[$key] = $exact % $total;
        }
        // Остаток копеек — ключам с наибольшим дробным остатком, при равенстве — по ключу.
        $left = $abs - array_sum($parts);
        uksort($remainders, static fn (string|int $a, string|int $b): int => [$remainders[$b], (string) $a] <=> [$remainders[$a], (string) $b]);
        foreach (array_keys($remainders) as $key) {
            if ($left-- <= 0) {
                break;
            }
            ++$parts[$key];
        }

        $out = [];
        foreach ($parts as $key => $minor) {
            if (0 !== $minor) {
                $out[(string) $key] = $sign * $minor;
            }
        }

        return $out;
    }
}
