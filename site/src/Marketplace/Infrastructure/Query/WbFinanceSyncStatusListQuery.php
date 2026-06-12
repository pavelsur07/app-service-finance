<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Query;

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
    ) {}

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
     * Последние N дней для server-side рендера карточки на странице маркетплейсов.
     * Окно ограничено днями (одна строка на день/report_type) — выборка bounded.
     *
     * @return list<array<string, mixed>>
     */
    public function findRecentDays(string $companyId, int $days = 14): array
    {
        $from = (new \DateTimeImmutable('today'))->modify(sprintf('-%d days', max(1, $days)));

        return $this->createByCompanyQueryBuilder($companyId, $from)
            ->executeQuery()
            ->fetchAllAssociative();
    }
}
