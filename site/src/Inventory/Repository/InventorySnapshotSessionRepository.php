<?php

declare(strict_types=1);

namespace App\Inventory\Repository;

use App\Inventory\Entity\InventorySnapshotSession;
use App\Inventory\Enum\SnapshotSessionStatus;
use App\Marketplace\Enum\MarketplaceType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Webmozart\Assert\Assert;

final class InventorySnapshotSessionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InventorySnapshotSession::class);
    }

    public function findLatestActiveByCompanyAndSource(string $companyId, MarketplaceType $source): ?InventorySnapshotSession
    {
        Assert::uuid($companyId);

        return $this->createQueryBuilder('session')
            ->andWhere('session.companyId = :companyId')
            ->andWhere('session.source = :source')
            ->andWhere('session.status IN (:activeStatuses)')
            ->setParameter('companyId', $companyId)
            ->setParameter('source', $source)
            ->setParameter('activeStatuses', [
                SnapshotSessionStatus::Pending,
                SnapshotSessionStatus::InProgress,
            ])
            ->orderBy('session.startedAt', 'DESC')
            ->addOrderBy('session.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
