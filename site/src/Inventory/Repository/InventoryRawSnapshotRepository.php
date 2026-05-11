<?php

declare(strict_types=1);

namespace App\Inventory\Repository;

use App\Inventory\Entity\InventoryRawSnapshot;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Webmozart\Assert\Assert;

final class InventoryRawSnapshotRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InventoryRawSnapshot::class);
    }

    /**
     * @return list<InventoryRawSnapshot>
     */
    public function findBySessionAndCompanyOrdered(string $snapshotSessionId, string $companyId): array
    {
        Assert::uuid($snapshotSessionId);
        Assert::uuid($companyId);

        return $this->createQueryBuilder('r')
            ->andWhere('r.snapshotSessionId = :snapshotSessionId')
            ->andWhere('r.companyId = :companyId')
            ->setParameter('snapshotSessionId', $snapshotSessionId)
            ->setParameter('companyId', $companyId)
            ->addOrderBy('r.pageNumber', 'ASC')
            ->addOrderBy('r.fetchedAt', 'ASC')
            ->addOrderBy('r.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
