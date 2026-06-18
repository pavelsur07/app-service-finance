<?php

declare(strict_types=1);

namespace App\Ingestion\Repository;

use App\Ingestion\Entity\IngestCursor;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<IngestCursor>
 */
final class IngestCursorRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IngestCursor::class);
    }

    public function findOne(
        string $companyId,
        string $connectionRef,
        string $resourceType,
        string $shopRef = '',
    ): ?IngestCursor {
        return $this->createQueryBuilder('cursor')
            ->andWhere('cursor.companyId = :companyId')
            ->andWhere('cursor.connectionRef = :connectionRef')
            ->andWhere('cursor.resourceType = :resourceType')
            ->andWhere('cursor.shopRef = :shopRef')
            ->setParameter('companyId', $companyId)
            ->setParameter('connectionRef', $connectionRef)
            ->setParameter('resourceType', $resourceType)
            ->setParameter('shopRef', $shopRef)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function getOrCreate(
        string $companyId,
        string $connectionRef,
        string $resourceType,
        string $shopRef = '',
    ): IngestCursor {
        $cursor = $this->findOne($companyId, $connectionRef, $resourceType, $shopRef);
        if (null !== $cursor) {
            return $cursor;
        }

        $cursor = new IngestCursor($companyId, $connectionRef, $resourceType, $shopRef);
        $this->getEntityManager()->persist($cursor);

        return $cursor;
    }
}
