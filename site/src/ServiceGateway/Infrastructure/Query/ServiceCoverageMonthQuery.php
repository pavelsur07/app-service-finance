<?php

declare(strict_types=1);

namespace App\ServiceGateway\Infrastructure\Query;

use App\ServiceGateway\Application\DTO\ServiceCoverageMonth;
use App\ServiceGateway\Application\DTO\ServiceCoverageMonthCell;
use App\ServiceGateway\Application\DTO\ServiceCoverageSnapshotView;
use App\ServiceGateway\Application\DTO\ServiceCoverageStatusCounts;
use Doctrine\DBAL\Connection;
use Webmozart\Assert\Assert;

/**
 * Витрина покрытия сервиса за месяц: два запроса (снимок и строки), без N+1.
 * Данные не принадлежат компании — сводка без кабинетов, страница ROLE_ADMIN.
 */
final readonly class ServiceCoverageMonthQuery
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @param string $month YYYY-MM
     */
    public function fetch(string $service, string $month): ServiceCoverageMonth
    {
        Assert::regex($month, '/^\d{4}-\d{2}$/D');

        $snapshot = $this->connection->fetchAssociative(
            'SELECT generated_at, refreshed_at, period_from, period_to, cabinets_total
               FROM service_coverage_snapshot
              WHERE service = :service',
            ['service' => $service],
        );

        $rows = $this->connection->fetchAllAssociative(
            "SELECT resource_type, period_key, cabinets_expected, done, running, pending, not_ready,
                    failed_retryable, failed_permanent, last_success_at
               FROM service_coverage_row
              WHERE service = :service
                AND (period_key = 'ref' OR period_key = :month OR (length(period_key) = 10 AND left(period_key, 7) = :month))
              ORDER BY resource_type, period_key",
            ['service' => $service, 'month' => $month],
        );

        $cells = array_map(static fn (array $row): ServiceCoverageMonthCell => new ServiceCoverageMonthCell(
            resourceType: (string) $row['resource_type'],
            periodKey: (string) $row['period_key'],
            cabinetsExpected: (int) $row['cabinets_expected'],
            byStatus: new ServiceCoverageStatusCounts(
                done: (int) $row['done'],
                running: (int) $row['running'],
                pending: (int) $row['pending'],
                notReady: (int) $row['not_ready'],
                failedRetryable: (int) $row['failed_retryable'],
                failedPermanent: (int) $row['failed_permanent'],
            ),
            lastSuccessAt: null === $row['last_success_at'] ? null : new \DateTimeImmutable((string) $row['last_success_at']),
        ), $rows);

        return new ServiceCoverageMonth(
            service: $service,
            month: $month,
            snapshot: false === $snapshot ? null : new ServiceCoverageSnapshotView(
                generatedAt: new \DateTimeImmutable((string) $snapshot['generated_at']),
                refreshedAt: new \DateTimeImmutable((string) $snapshot['refreshed_at']),
                periodFrom: new \DateTimeImmutable((string) $snapshot['period_from']),
                periodTo: new \DateTimeImmutable((string) $snapshot['period_to']),
                cabinetsTotal: (int) $snapshot['cabinets_total'],
            ),
            cells: $cells,
        );
    }
}
