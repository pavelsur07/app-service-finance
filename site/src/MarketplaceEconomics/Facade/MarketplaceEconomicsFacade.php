<?php

declare(strict_types=1);

namespace App\MarketplaceEconomics\Facade;

use App\MarketplaceEconomics\Application\DTO\EconomicsDaySum;
use Doctrine\DBAL\Connection;

final readonly class MarketplaceEconomicsFacade implements MarketplaceEconomicsFacadeInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function daySums(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return array_map(static fn (array $r): EconomicsDaySum => new EconomicsDaySum(
            (string) $r['connection_ref'],
            (string) $r['operation_date'],
            (int) $r['lines_minor'],
            (int) $r['unallocated_minor'],
            (int) $r['costs_minor'],
        ), $this->connection->fetchAllAssociative(
            "SELECT d.connection_ref, d.operation_date, d.unallocated_minor,
                    COALESCE(SUM(l.amount_minor) FILTER (WHERE l.level NOT IN ('cost_price', 'info')), 0)::bigint AS lines_minor,
                    COALESCE(SUM(-l.amount_minor) FILTER (WHERE l.amount_minor < 0 AND l.level NOT IN ('cost_price', 'info')), 0)::bigint AS costs_minor
             FROM me_day d
             LEFT JOIN me_day_line l ON l.company_id = d.company_id AND l.channel = d.channel AND l.connection_ref = d.connection_ref AND l.operation_date = d.operation_date
             WHERE d.company_id = :company AND d.operation_date BETWEEN :from AND :to
             GROUP BY d.connection_ref, d.operation_date, d.unallocated_minor ORDER BY d.connection_ref, d.operation_date",
            ['company' => $companyId, 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')],
        ));
    }
}
