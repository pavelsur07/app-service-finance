<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Infrastructure\Api\Wildberries;

use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceAds\Application\DTO\AdRawEntry;
use App\MarketplaceAds\Infrastructure\Api\Contract\AdRawDataParserInterface;
use App\Shared\Domain\ValueObject\Money;

/**
 * Converts the combined WB daily payload into campaign/SKU expense entries.
 *
 * `/adv/v1/upd.updSum` is the actual campaign expense. Nested
 * `/adv/v3/fullstats.days[].apps[].nms[].sum` values are used only as
 * allocation weights. The resulting SKU amounts always add up to the actual
 * campaign expense; when no positive weights exist, the expense is emitted as
 * an explicit unallocated entry.
 */
final class WildberriesAdRawDataParser implements AdRawDataParserInterface
{
    private const SCHEMA = 'wb-ad-daily-spend-v1';
    private const CALCULATION_SCALE = 12;
    private const CURRENCY = 'RUB';

    public function supports(string $marketplace): bool
    {
        return MarketplaceType::WILDBERRIES->value === $marketplace;
    }

    public function parse(string $rawPayload): array
    {
        $payload = json_decode($rawPayload, true, 512, \JSON_THROW_ON_ERROR);
        if (!is_array($payload) || array_is_list($payload)) {
            throw new \UnexpectedValueException('WB daily ad payload must be a JSON object.');
        }
        if (self::SCHEMA !== ($payload['schema'] ?? null)) {
            throw new \UnexpectedValueException('Unsupported WB daily ad payload schema.');
        }

        $expenses = $this->objectList($payload['expenses'] ?? null, 'expenses');
        $statistics = $this->objectList($payload['statistics'] ?? null, 'statistics');
        $campaigns = $this->aggregateExpenses($expenses);
        $statsByCampaign = $this->aggregateStatistics($statistics, $campaigns);

        $entries = [];
        foreach ($campaigns as $campaignKey => $campaign) {
            array_push(
                $entries,
                ...$this->allocateCampaign(
                    campaignId: $campaign['id'],
                    campaignName: $campaign['name'],
                    actual: Money::fromString($campaign['actual'], self::CURRENCY),
                    skuStats: $statsByCampaign[$campaignKey] ?? [],
                ),
            );
        }

        return $entries;
    }

    /**
     * @param list<array<string, mixed>> $expenses
     *
     * @return array<string, array{id: string, name: string, actual: string}>
     */
    private function aggregateExpenses(array $expenses): array
    {
        $campaigns = [];
        foreach ($expenses as $index => $row) {
            $campaignId = $this->identifier($row['advertId'] ?? null, "expenses[$index].advertId");
            $campaignKey = 'id:'.$campaignId;
            $actual = $this->decimal($row['updSum'] ?? null, "expenses[$index].updSum");
            $nameValue = $row['campName'] ?? '';
            if (!is_string($nameValue)) {
                throw new \UnexpectedValueException("expenses[$index].campName must be a string.");
            }
            $name = trim($nameValue);

            if (!isset($campaigns[$campaignKey])) {
                $campaigns[$campaignKey] = [
                    'id' => $campaignId,
                    'name' => '' !== $name ? $name : 'WB campaign '.$campaignId,
                    'actual' => '0',
                ];
            } elseif ('' !== $name) {
                $campaigns[$campaignKey]['name'] = $name;
            }

            $campaigns[$campaignKey]['actual'] = bcadd(
                $campaigns[$campaignKey]['actual'],
                $actual,
                self::CALCULATION_SCALE,
            );
        }

        uasort($campaigns, static fn (array $left, array $right): int => strnatcmp($left['id'], $right['id']));

        return $campaigns;
    }

    /**
     * @param list<array<string, mixed>> $statistics
     * @param array<string, array{id: string, name: string, actual: string}> $campaigns
     *
     * @return array<string, array<string, array{weight: string, impressions: int, clicks: int}>>
     */
    private function aggregateStatistics(array $statistics, array $campaigns): array
    {
        $result = [];
        foreach ($statistics as $campaignIndex => $campaignRow) {
            $campaignId = $this->identifier(
                $campaignRow['advertId'] ?? null,
                "statistics[$campaignIndex].advertId",
            );
            $campaignKey = 'id:'.$campaignId;
            if (!isset($campaigns[$campaignKey])) {
                continue;
            }

            $days = $this->objectList(
                $campaignRow['days'] ?? [],
                "statistics[$campaignIndex].days",
            );
            foreach ($days as $dayIndex => $day) {
                $apps = $this->objectList(
                    $day['apps'] ?? [],
                    "statistics[$campaignIndex].days[$dayIndex].apps",
                );
                foreach ($apps as $appIndex => $app) {
                    $nms = $this->objectList(
                        $app['nms'] ?? [],
                        "statistics[$campaignIndex].days[$dayIndex].apps[$appIndex].nms",
                    );
                    foreach ($nms as $nmIndex => $nm) {
                        $path = "statistics[$campaignIndex].days[$dayIndex].apps[$appIndex].nms[$nmIndex]";
                        $nmId = $this->identifier($nm['nmId'] ?? null, $path.'.nmId');
                        $nmKey = 'nm:'.$nmId;
                        $weight = $this->decimal($nm['sum'] ?? null, $path.'.sum');
                        if (bccomp($weight, '0', self::CALCULATION_SCALE) < 0) {
                            throw new \UnexpectedValueException($path.'.sum cannot be negative.');
                        }

                        $result[$campaignKey][$nmKey] ??= [
                            'nmId' => $nmId,
                            'weight' => '0',
                            'impressions' => 0,
                            'clicks' => 0,
                        ];
                        $result[$campaignKey][$nmKey]['weight'] = bcadd(
                            $result[$campaignKey][$nmKey]['weight'],
                            $weight,
                            self::CALCULATION_SCALE,
                        );
                        $result[$campaignKey][$nmKey]['impressions'] = $this->addCounter(
                            $result[$campaignKey][$nmKey]['impressions'],
                            $this->nonNegativeInt($nm['views'] ?? '0', $path.'.views'),
                            $path.'.views',
                        );
                        $result[$campaignKey][$nmKey]['clicks'] = $this->addCounter(
                            $result[$campaignKey][$nmKey]['clicks'],
                            $this->nonNegativeInt($nm['clicks'] ?? '0', $path.'.clicks'),
                            $path.'.clicks',
                        );
                    }
                }
            }
        }

        foreach ($result as &$skuStats) {
            uasort($skuStats, static fn (array $left, array $right): int => strnatcmp($left['nmId'], $right['nmId']));
        }
        unset($skuStats);

        return $result;
    }

    /**
     * @param array<string, array{nmId: string, weight: string, impressions: int, clicks: int}> $skuStats
     *
     * @return list<AdRawEntry>
     */
    private function allocateCampaign(
        string $campaignId,
        string $campaignName,
        Money $actual,
        array $skuStats,
    ): array {
        if ($actual->isZero()) {
            return [];
        }

        $positive = array_filter(
            $skuStats,
            static fn (array $stats): bool => bccomp($stats['weight'], '0', self::CALCULATION_SCALE) > 0,
        );
        if ([] === $positive) {
            return [$this->unallocatedEntry($campaignId, $campaignName, $actual)];
        }

        $totalWeight = '0';
        foreach ($positive as $stats) {
            $totalWeight = bcadd($totalWeight, $stats['weight'], self::CALCULATION_SCALE);
        }

        $absoluteMinor = $actual->abs()->amountMinor();
        $allocatedMinor = [];
        $allocatedTotal = 0;
        $largestWeightNmId = null;

        foreach ($positive as $nmKey => $stats) {
            $minor = (int) bcdiv(
                bcmul((string) $absoluteMinor, $stats['weight'], self::CALCULATION_SCALE),
                $totalWeight,
                0,
            );
            $allocatedMinor[$nmKey] = $minor;
            $allocatedTotal += $minor;

            if (
                null === $largestWeightNmId
                || bccomp(
                    $stats['weight'],
                    $positive[$largestWeightNmId]['weight'],
                    self::CALCULATION_SCALE,
                ) > 0
            ) {
                $largestWeightNmId = $nmKey;
            }
        }

        if (null !== $largestWeightNmId) {
            $allocatedMinor[$largestWeightNmId] += $absoluteMinor - $allocatedTotal;
        }

        $sign = $actual->isNegative() ? -1 : 1;
        $entries = [];
        foreach ($positive as $nmKey => $stats) {
            $entries[] = new AdRawEntry(
                campaignId: $campaignId,
                campaignName: $campaignName,
                parentSku: $stats['nmId'],
                cost: Money::fromMinor($allocatedMinor[$nmKey] * $sign, self::CURRENCY)->toDecimalString(),
                impressions: $stats['impressions'],
                clicks: $stats['clicks'],
            );
        }

        return $entries;
    }

    private function unallocatedEntry(
        string $campaignId,
        string $campaignName,
        Money $actual,
    ): AdRawEntry {
        return new AdRawEntry(
            campaignId: $campaignId,
            campaignName: $campaignName,
            parentSku: AdRawEntry::UNALLOCATED_PARENT_SKU,
            cost: $actual->toDecimalString(),
            impressions: 0,
            clicks: 0,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function objectList(mixed $value, string $path): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new \UnexpectedValueException($path.' must be a JSON list.');
        }
        foreach ($value as $row) {
            if (!is_array($row) || array_is_list($row)) {
                throw new \UnexpectedValueException($path.' items must be JSON objects.');
            }
        }

        /* @var list<array<string, mixed>> $value */
        return $value;
    }

    private function identifier(mixed $value, string $path): string
    {
        if (!is_string($value) || !ctype_digit($value) || bccomp($value, '0', 0) <= 0) {
            throw new \UnexpectedValueException($path.' must be a positive decimal string.');
        }

        return $value;
    }

    private function decimal(mixed $value, string $path): string
    {
        if (
            !is_string($value)
            || 1 !== preg_match('/^-?\d+(?:\.(\d+))?$/', $value, $matches)
            || strlen($matches[1] ?? '') > self::CALCULATION_SCALE
        ) {
            throw new \UnexpectedValueException($path.' must be a decimal string.');
        }

        return $value;
    }

    private function nonNegativeInt(mixed $value, string $path): int
    {
        if (!is_string($value) || !ctype_digit($value)) {
            throw new \UnexpectedValueException($path.' must be a non-negative integer string.');
        }
        if (bccomp($value, (string) \PHP_INT_MAX, 0) > 0) {
            throw new \UnexpectedValueException($path.' exceeds the integer range.');
        }

        return (int) $value;
    }

    private function addCounter(int $current, int $increment, string $path): int
    {
        if ($increment > \PHP_INT_MAX - $current) {
            throw new \UnexpectedValueException($path.' aggregate exceeds the integer range.');
        }

        return $current + $increment;
    }
}
