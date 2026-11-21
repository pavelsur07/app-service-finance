<?php

declare(strict_types=1);

namespace App\MarketplaceEconomics\Infrastructure\Query;

use App\MarketplaceEconomics\Domain\EconomicsLevel;
use App\MarketplaceEconomics\Domain\SkuMetrics;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Свод витрины за период по листингам. Строки без SKU — ключ `''` (нераспределённое).
 *
 * НДС к уплате (`SkuMetrics::$vat`) — в SQL точной арифметикой: выручка с СПП (выручка − СПП −
 * соинвестирование) × ставка / (100 + ставка), округление по листингу × дню. День — общий делитель R1
 * (листинг × период) и R2 (любой шаг), поэтому их итоги совпадают.
 */
final readonly class SkuEconomicsQuery
{
    private const DISCOUNT_MEASURES = "('spp', 'coinvestment')";

    public function __construct(private Connection $connection)
    {
    }

    /**
     * @param array<string, int> $vatRates Y-m => ставка НДС, % (`VatRatesByMonth`); нет месяца — без НДС
     *
     * @return array<string, SkuMetrics> listingId (`''` — без SKU) => показатели
     */
    public function byListing(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to, ?string $connectionRef = null, ?string $channel = null, array $vatRates = [], bool $allocated = true): array
    {
        [$where, $params, $types] = $this->where($companyId, $from, $to, $connectionRef, $channel, null, $allocated);

        return $this->collect("COALESCE(listing_id::text, '')", $where, $params, $types, $vatRates);
    }

    /**
     * Свод витрины по шагам периода (`day` / `week` / `month`, неделя — с понедельника).
     * `$listingIds` — только эти листинги (без нераспределённого); `null` — весь кабинет.
     *
     * @param list<string>|null $listingIds
     * @param array<string, int> $vatRates Y-m => ставка НДС, %
     *
     * @return array<string, SkuMetrics> начало шага Y-m-d => показатели
     */
    public function byStep(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to, string $step, ?string $connectionRef = null, ?array $listingIds = null, ?string $channel = null, array $vatRates = [], bool $allocated = true): array
    {
        if (!\in_array($step, ['day', 'week', 'month'], true)) {
            throw new \InvalidArgumentException('step: '.$step);
        }
        if ([] === $listingIds) {
            return [];
        }
        [$where, $params, $types] = $this->where($companyId, $from, $to, $connectionRef, $channel, $listingIds, $allocated);

        return $this->collect("to_char(date_trunc('$step', operation_date), 'YYYY-MM-DD')", $where, $params, $types, $vatRates);
    }

    /**
     * @param list<string>|null $listingIds
     *
     * @return array{0: string, 1: array<string, mixed>, 2: array<string, ArrayParameterType>}
     */
    private function where(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to, ?string $connectionRef, ?string $channel, ?array $listingIds, bool $allocated): array
    {
        $where = 'company_id = :company AND operation_date BETWEEN :from AND :to';
        if (!$allocated) {
            // строки разнесения идут парой (доля на SKU + обратная без SKU) и дают 0 — без них итог тот же
            $where .= ' AND allocation_key IS NULL';
        }
        $params = ['company' => $companyId, 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')];
        $types = [];
        if (null !== $connectionRef) {
            $where .= ' AND connection_ref = :connection';
            $params['connection'] = $connectionRef;
        }
        if (null !== $channel) {
            $where .= ' AND channel = :channel';
            $params['channel'] = $channel;
        }
        if (null !== $listingIds) {
            $where .= ' AND listing_id IN (:listings)';
            $params['listings'] = $listingIds;
            $types['listings'] = ArrayParameterType::STRING;
        }

        return [$where, $params, $types];
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, ArrayParameterType> $types
     * @param array<string, int> $vatRates
     *
     * @return array<string, SkuMetrics>
     */
    private function collect(string $bucket, string $where, array $params, array $types, array $vatRates): array
    {
        $metrics = [];
        $sql = "SELECT $bucket AS bucket, measure, level, SUM(amount_minor)::bigint AS amount, COALESCE(SUM(quantity), 0)::bigint AS qty
                FROM me_day_line WHERE $where GROUP BY 1, 2, 3";
        foreach ($this->connection->fetchAllAssociative($sql, $params, $types) as $row) {
            $key = (string) $row['bucket'];
            $metrics[$key] ??= new SkuMetrics();
            $metrics[$key]->add((string) $row['measure'], EconomicsLevel::from((string) $row['level']), (int) $row['amount'], (int) $row['qty']);
        }

        $rate = $this->rateSql($vatRates);
        if (null === $rate) {
            return $metrics;
        }
        $discounts = self::DISCOUNT_MEASURES;
        $vatSql = "SELECT bucket, SUM(vat)::bigint AS vat FROM (
                    SELECT $bucket AS bucket,
                           ROUND(SUM(CASE WHEN level = 'revenue' THEN amount_minor ELSE -amount_minor END)::numeric * ($rate) / (100 + ($rate))) AS vat
                    FROM me_day_line WHERE $where AND (level = 'revenue' OR measure IN $discounts)
                    GROUP BY 1, listing_id, operation_date
                ) d GROUP BY 1";
        foreach ($this->connection->fetchAllAssociative($vatSql, $params, $types) as $row) {
            $key = (string) $row['bucket'];
            $metrics[$key] ??= new SkuMetrics();
            $metrics[$key]->vat = -(int) $row['vat'];
        }

        return $metrics;
    }

    /**
     * Ставка дня строки как SQL-выражение; все месяцы без НДС — null (запрос НДС не нужен).
     *
     * @param array<string, int> $vatRates
     */
    private function rateSql(array $vatRates): ?string
    {
        $cases = '';
        foreach ($vatRates as $month => $rate) {
            if ($rate > 0 && 1 === preg_match('/^\d{4}-\d{2}$/', (string) $month)) {
                $cases .= sprintf(" WHEN '%s' THEN %d", $month, $rate);
            }
        }

        return '' === $cases ? null : "CASE to_char(operation_date, 'YYYY-MM')$cases ELSE 0 END";
    }

    /**
     * Кабинеты компании, по которым есть витрина, и итоги качества за период.
     *
     * @return array{connections: list<string>, channels: list<string>, days: int, daysWithoutCostPrice: int}
     */
    public function coverage(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $connections = array_map('strval', $this->connection->fetchFirstColumn(
            'SELECT DISTINCT connection_ref FROM me_day WHERE company_id = :company ORDER BY 1',
            ['company' => $companyId],
        ));
        $channels = array_map('strval', $this->connection->fetchFirstColumn(
            'SELECT DISTINCT channel FROM me_day WHERE company_id = :company ORDER BY 1',
            ['company' => $companyId],
        ));
        $row = $this->connection->fetchAssociative(
            'SELECT count(*) AS days, count(*) FILTER (WHERE without_cost_price > 0) AS without FROM me_day WHERE company_id = :company AND operation_date BETWEEN :from AND :to',
            ['company' => $companyId, 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')],
        ) ?: ['days' => 0, 'without' => 0];

        return ['connections' => $connections, 'channels' => $channels, 'days' => (int) $row['days'], 'daysWithoutCostPrice' => (int) $row['without']];
    }
}
