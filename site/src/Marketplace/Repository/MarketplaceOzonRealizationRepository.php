<?php

declare(strict_types=1);

namespace App\Marketplace\Repository;

use App\Marketplace\Entity\MarketplaceOzonRealization;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;

class MarketplaceOzonRealizationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MarketplaceOzonRealization::class);
    }

    /**
     * Проверить — загружена ли реализация за период.
     */
    public function existsForPeriod(
        string $companyId,
        string $periodFrom,
        string $periodTo,
    ): bool {
        $count = $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.companyId = :companyId')
            ->andWhere('r.periodFrom = :periodFrom')
            ->andWhere('r.periodTo = :periodTo')
            ->setParameter('companyId', $companyId)
            ->setParameter('periodFrom', new \DateTimeImmutable($periodFrom))
            ->setParameter('periodTo', new \DateTimeImmutable($periodTo))
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count > 0;
    }

    /**
     * Пометить строки реализации за период как обработанные.
     *
     * @return int количество обновлённых записей
     */
    public function markProcessed(
        string $companyId,
        string $plDocumentId,
        string $periodFrom,
        string $periodTo,
    ): int {
        return $this->createQueryBuilder('r')
            ->update()
            ->set('r.plDocumentId', ':plDocumentId')
            ->where('r.companyId = :companyId')
            ->andWhere('r.periodFrom = :periodFrom')
            ->andWhere('r.periodTo = :periodTo')
            ->andWhere('r.plDocumentId IS NULL')
            ->setParameter('companyId', $companyId)
            ->setParameter('plDocumentId', $plDocumentId)
            ->setParameter('periodFrom', new \DateTimeImmutable($periodFrom))
            ->setParameter('periodTo', new \DateTimeImmutable($periodTo))
            ->getQuery()
            ->execute();
    }

    public function save(MarketplaceOzonRealization $entity): void
    {
        $this->getEntityManager()->persist($entity);
        $this->getEntityManager()->flush();
    }

    /**
     * @param string[] $plDocumentIds
     */
    public function unmarkProcessedByDocumentIds(string $companyId, array $plDocumentIds): int
    {
        if ($plDocumentIds === []) {
            return 0;
        }

        return $this->getEntityManager()
            ->getConnection()
            ->executeStatement(
                'UPDATE marketplace_ozon_realizations
                 SET pl_document_id = NULL
                 WHERE company_id = :companyId
                   AND pl_document_id IN (:plDocumentIds)',
                [
                    'companyId' => $companyId,
                    'plDocumentIds' => $plDocumentIds,
                ],
                [
                    'plDocumentIds' => ArrayParameterType::STRING,
                ],
            );
    }

    public function unmarkProcessedByPeriod(
        string $companyId,
        string $periodFrom,
        string $periodTo,
    ): int {
        return $this->getEntityManager()
            ->getConnection()
            ->executeStatement(
                'UPDATE marketplace_ozon_realizations
                 SET pl_document_id = NULL
                 WHERE company_id = :companyId
                   AND period_from = :periodFrom
                   AND period_to = :periodTo
                   AND pl_document_id IS NOT NULL',
                [
                    'companyId' => $companyId,
                    'periodFrom' => $periodFrom,
                    'periodTo' => $periodTo,
                ],
            );
    }
}
