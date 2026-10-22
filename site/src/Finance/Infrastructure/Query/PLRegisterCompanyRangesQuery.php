<?php

declare(strict_types=1);

namespace App\Finance\Infrastructure\Query;

use App\Finance\Application\DTO\PLRegisterCompanyRange;
use Doctrine\DBAL\Connection;

/**
 * Компании, у которых есть что сверять в регистре ОПиУ: активные неудалённые документы или строки
 * `pl_daily_totals`, — с окном от первого до последнего дня. Источник обхода для
 * `app:finance:verify-pl-register`; ничего не пишет.
 */
final readonly class PLRegisterCompanyRangesQuery
{
    public function __construct(private Connection $db)
    {
    }

    /**
     * @return list<PLRegisterCompanyRange>
     */
    public function __invoke(?string $companyId = null): array
    {
        $rows = $this->db->fetchAllAssociative(
            "SELECT company_id::text AS company_id, MIN(day)::text AS first_day, MAX(day)::text AS last_day
             FROM (
                 SELECT company_id, date::date AS day FROM documents
                 WHERE deleted_at IS NULL AND status = 'ACTIVE'
                 UNION ALL
                 SELECT company_id, date AS day FROM pl_daily_totals
             ) s
             WHERE (CAST(:company_id AS uuid) IS NULL OR company_id = CAST(:company_id AS uuid))
             GROUP BY company_id
             ORDER BY company_id",
            ['company_id' => $companyId],
        );

        return array_map(
            static fn (array $row): PLRegisterCompanyRange => new PLRegisterCompanyRange(
                (string) $row['company_id'],
                new \DateTimeImmutable((string) $row['first_day']),
                new \DateTimeImmutable((string) $row['last_day']),
            ),
            $rows,
        );
    }
}
