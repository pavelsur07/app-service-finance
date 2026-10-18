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
 * Витрина покрытия сервиса за месяц: один запрос (снимок + строки), без N+1.
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

        // Один запрос, не два: отдельные SELECT снимка и строк при READ COMMITTED
        // могли бы увидеть разные стороны замены снимка cron'ом. Строки без
        // снимка не бывает — Action пишет их одной транзакцией.
        $joined = $this->connection->fetchAllAssociative(
            "SELECT s.generated_at, s.refreshed_at, s.period_from, s.period_to, s.cabinets_total,
                    r.resource_type, r.period_key, r.cabinets_expected, r.done, r.running, r.pending, r.not_ready,
                    r.failed_retryable, r.failed_permanent, r.last_success_at
               FROM service_coverage_snapshot s
               LEFT JOIN service_coverage_row r
                      ON r.service = s.service
                     AND (r.period_key = 'ref' OR r.period_key = :month OR (length(r.period_key) = 10 AND left(r.period_key, 7) = :month))
              WHERE s.service = :service
              ORDER BY r.resource_type, r.period_key",
            ['service' => $service, 'month' => $month],
        );
        $snapshot = $joined[0] ?? false;
        $rows = array_values(array_filter($joined, static fn (array $row): bool => null !== $row['resource_type']));

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
