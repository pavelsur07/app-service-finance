<?php

declare(strict_types=1);

namespace App\Marketplace\Repository;

use App\Marketplace\Entity\MappingError;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MappingError>
 */
class MappingErrorRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MappingError::class);
    }

    /**
     * Найти существующую запись для upsert.
     */
    public function findForUpsert(
        string $companyId,
        string $marketplace,
        int $year,
        int $month,
        string $serviceName,
    ): ?MappingError {
        return $this->findOneBy([
            'companyId'   => $companyId,
            'marketplace' => $marketplace,
            'year'        => $year,
            'month'       => $month,
            'serviceName' => $serviceName,
        ]);
    }

    /**
     * Все нерешённые ошибки для админки — с данными компании и контактом.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findUnresolvedWithCompanyInfo(): array
    {
        return $this->getEntityManager()->getConnection()->fetchAllAssociative(
            <<<'SQL'
            SELECT
                me.id,
                me.company_id,
                c.name                          AS company_name,
                u.email                         AS contact_email,
                me.marketplace,
                me.year,
                me.month,
                me.service_name,
                me.operation_type,
                me.total_amount,
                me.rows_count,
                me.sample_raw_json,
                me.detected_at,
                me.resolved_at
            FROM marketplace_mapping_errors me
            INNER JOIN companies c ON c.id = me.company_id
            LEFT JOIN "user" u ON u.id = c.user_id
            WHERE me.resolved_at IS NULL
            ORDER BY me.detected_at DESC
            SQL,
        );
    }

    /**
     * Все ошибки (включая решённые) с пагинацией.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findAllWithCompanyInfo(int $limit = 100, int $offset = 0): array
    {
        return $this->getEntityManager()->getConnection()->fetchAllAssociative(
            <<<SQL
            SELECT
                me.id,
                me.company_id,
                c.name                          AS company_name,
                u.email                         AS contact_email,
                me.marketplace,
                me.year,
                me.month,
                me.service_name,
                me.operation_type,
                me.total_amount,
                me.rows_count,
                me.sample_raw_json,
                me.detected_at,
                me.resolved_at
            FROM marketplace_mapping_errors me
            INNER JOIN companies c ON c.id = me.company_id
            LEFT JOIN "user" u ON u.id = c.user_id
            ORDER BY me.resolved_at NULLS FIRST, me.detected_at DESC
            LIMIT {$limit} OFFSET {$offset}
            SQL,
        );
    }
}
