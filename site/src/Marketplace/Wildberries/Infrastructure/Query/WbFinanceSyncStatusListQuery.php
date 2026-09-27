<?php

declare(strict_types=1);

namespace App\Marketplace\Wildberries\Infrastructure\Query;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

/**
 * DBAL Query: статусы загрузки финансовых отчётов WB по дням для компании.
 * Источник для UI-таблицы статусов синхронизации и API endpoint'а.
 */
final class WbFinanceSyncStatusListQuery
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function createByCompanyQueryBuilder(string $companyId, ?\DateTimeImmutable $from = null): QueryBuilder
    {
        $qb = $this->connection->createQueryBuilder()
            ->select(
                's.id',
                's.connection_id',
                's.marketplace',
                's.report_type',
                's.business_date',
                's.status',
                's.mode',
                's.records_count',
                's.attempts',
                's.next_retry_at',
                's.last_error_class',
                's.last_error_message',
                's.last_error_status_code',
                's.started_at',
                's.finished_at',
                's.updated_at',
            )
            ->from('marketplace_financial_report_sync_statuses', 's')
            ->where('s.company_id = :companyId')
            ->andWhere('s.marketplace = :marketplace')
            ->andWhere('s.report_type = :reportType')
            ->setParameter('companyId', $companyId)
            ->setParameter('marketplace', 'wildberries')
            ->setParameter('reportType', 'sales_report')
            ->orderBy('s.business_date', 'DESC');

        if (null !== $from) {
            $qb->andWhere('s.business_date >= :fromDate')
                ->setParameter('fromDate', $from->format('Y-m-d'));
        }

        return $qb;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findCurrentMonthDays(string $companyId): array
    {
        return $this->createByCompanyQueryBuilder($companyId, new \DateTimeImmutable('first day of this month'))
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Дни календарного месяца, которому принадлежит $month.
     *
     * @return list<array<string, mixed>>
     */
    public function findMonthDays(string $companyId, \DateTimeImmutable $month): array
    {
        $monthStart = $month->modify('first day of this month')->setTime(0, 0);

        return $this->createByCompanyQueryBuilder($companyId, $monthStart)
            ->andWhere('s.business_date < :toDate')
            ->setParameter('toDate', $monthStart->modify('first day of next month')->format('Y-m-d'))
            ->executeQuery()
            ->fetchAllAssociative();
    }
}
