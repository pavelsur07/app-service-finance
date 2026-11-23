<?php

declare(strict_types=1);

namespace App\MarketplaceWb\Domain;

use App\MarketplaceOperations\Enum\OperationKind;
use App\Shared\Domain\ValueObject\Money;

/**
 * Кампания дня из фактов `wb.ads.daily` (`{"advertId","expenses","statistics"}`, wb-fetcher) → расход
 * `ads_cpc` = −Σ `expenses[].updSum`, разнесённый по nmId пропорционально Σ `statistics.days[].apps[].nms[].sum`
 * методом наибольшего остатка; нет весов — одна операция без SKU. Сумма кампании не меняется.
 */
final class AdsDayTranslator
{
    public const RESOURCE = 'wb.ads.daily';
    public const SOURCE_TYPE = 'wb:ads:campaign';
    public const ALLOCATION_KEY = 'ads_statistics_share';

    /**
     * @param array<string, mixed> $campaign
     *
     * @return list<WbComponent>
     */
    public static function translate(string $sourceKey, array $campaign): array
    {
        $spend = 0;
        foreach (self::items($campaign['expenses'] ?? null) as $expense) {
            $spend += self::minor($expense['updSum'] ?? null);
        }

        $weights = [];
        $statistics = \is_array($campaign['statistics'] ?? null) ? $campaign['statistics'] : [];
        foreach (self::items($statistics['days'] ?? null) as $day) {
            foreach (self::items($day['apps'] ?? null) as $app) {
                foreach (self::items($app['nms'] ?? null) as $nm) {
                    $nmId = $nm['nmId'] ?? null;
                    $minor = self::minor($nm['sum'] ?? null);
                    if (\is_int($nmId) && $nmId > 0 && $minor > 0) {
                        $weights[(string) $nmId] = ($weights[(string) $nmId] ?? 0) + $minor;
                    }
                }
            }
        }

        $components = [];
        // числовые ключи nmId PHP хранит как int — приводим обратно к строке
        foreach (self::split(-$spend, $weights) as $nmId => $minor) {
            $components[] = new WbComponent(self::SOURCE_TYPE, Money::fromMinor($minor, 'RUB'), $sourceKey, '' === $nmId ? null : (string) $nmId, null, null, OperationKind::ADS_CPC);
        }

        return $components;
    }

    /**
     * Делит сумму по весам; ключ `''` — без SKU.
     * ponytail: копия `MarketplaceOzon\Domain\AdsStatisticsShares::split` (чужой модуль не импортируется);
     * вынести в Shared, когда появится третий переводчик.
     *
     * @param array<array-key, int> $weights
     *
     * @return array<array-key, int> Σ = $amountMinor, нулевые доли опущены (числовой ключ — int)
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

    /**
     * @return list<array<string, mixed>>
     */
    private static function items(mixed $list): array
    {
        return \is_array($list) ? array_values(array_filter($list, 'is_array')) : [];
    }

    private static function minor(mixed $value): int
    {
        if (\is_int($value) || \is_float($value)) {
            $value = (string) $value;
        }

        return \is_string($value) && 1 === preg_match('/^-?\d+(\.\d+)?$/', trim($value))
            ? Money::fromString(trim($value), 'RUB')->amountMinor()
            : 0;
    }
}
