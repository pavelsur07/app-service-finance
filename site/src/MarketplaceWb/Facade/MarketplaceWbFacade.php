<?php

declare(strict_types=1);

namespace App\MarketplaceWb\Facade;

use App\MarketplaceWb\Application\DTO\WbDayState;
use App\MarketplaceWb\Domain\SalesReportTranslator;
use Doctrine\DBAL\Connection;

final readonly class MarketplaceWbFacade implements MarketplaceWbFacadeInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function dayStates(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return array_map(static fn (array $r): WbDayState => new WbDayState(
            (string) $r['connection_ref'],
            (string) $r['operation_date'],
            (int) $r['facts_revision'],
            SalesReportTranslator::RULES_VERSION === $r['rules_version'],
            (int) $r['total_minor'],
            (int) $r['canon_minor'],
            (int) $r['quarantine_minor'],
        ), $this->connection->fetchAllAssociative(
            'SELECT connection_ref, operation_date, facts_revision, rules_version, total_minor, canon_minor, quarantine_minor FROM mwb_day
             WHERE company_id = :company AND operation_date BETWEEN :from AND :to ORDER BY connection_ref, operation_date',
            ['company' => $companyId, 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')],
        ));
    }
}
