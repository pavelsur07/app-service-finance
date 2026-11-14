<?php

declare(strict_types=1);

namespace App\MarketplaceEconomics\Infrastructure\Query;

use App\MarketplaceEconomics\Domain\EconomicsLevel;
use App\MarketplaceEconomics\Domain\SkuMetrics;
use Doctrine\DBAL\Connection;

/** Свод витрины за период по листингам. Строки без SKU — ключ `''` (нераспределённое). */
final readonly class SkuEconomicsQuery
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return array<string, SkuMetrics> listingId (`''` — без SKU) => показатели
     */
    public function byListing(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to, ?string $connectionRef = null): array
    {
        $sql = 'SELECT COALESCE(listing_id::text, \'\') AS listing, measure, level, SUM(amount_minor)::bigint AS amount, COALESCE(SUM(quantity), 0)::bigint AS qty
                FROM me_day_line WHERE company_id = :company AND operation_date BETWEEN :from AND :to';
        $params = ['company' => $companyId, 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')];
        if (null !== $connectionRef) {
            $sql .= ' AND connection_ref = :connection';
            $params['connection'] = $connectionRef;
        }

        $metrics = [];
        foreach ($this->connection->fetchAllAssociative($sql.' GROUP BY 1, 2, 3', $params) as $row) {
            $listing = (string) $row['listing'];
            $metrics[$listing] ??= new SkuMetrics();
            $metrics[$listing]->add((string) $row['measure'], EconomicsLevel::from((string) $row['level']), (int) $row['amount'], (int) $row['qty']);
        }

        return $metrics;
    }

    /**
     * Кабинеты компании, по которым есть витрина, и итоги качества за период.
     *
     * @return array{connections: list<string>, days: int, daysWithoutCostPrice: int}
     */
    public function coverage(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $connections = array_map('strval', $this->connection->fetchFirstColumn(
            'SELECT DISTINCT connection_ref FROM me_day WHERE company_id = :company ORDER BY 1',
            ['company' => $companyId],
        ));
        $row = $this->connection->fetchAssociative(
            'SELECT count(*) AS days, count(*) FILTER (WHERE without_cost_price > 0) AS without FROM me_day WHERE company_id = :company AND operation_date BETWEEN :from AND :to',
            ['company' => $companyId, 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')],
        ) ?: ['days' => 0, 'without' => 0];

        return ['connections' => $connections, 'days' => (int) $row['days'], 'daysWithoutCostPrice' => (int) $row['without']];
    }
}
