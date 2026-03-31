<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Repository;

use App\MarketplaceAnalytics\Entity\ListingDailySnapshot;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class ListingDailySnapshotRepository extends ServiceEntityRepository implements ListingDailySnapshotRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ListingDailySnapshot::class);
    }

    public function save(ListingDailySnapshot $snapshot): void
    {
        $this->getEntityManager()->persist($snapshot);
    }

    /**
     * @return ListingDailySnapshot[]
     */
    public function findByCompanyAndPeriod(
        string $companyId,
        \DateTimeImmutable $dateFrom,
        \DateTimeImmutable $dateTo,
        ?string $marketplace = null,
    ): array {
        $qb = $this->createQueryBuilder('s')
            ->where('s.companyId = :companyId')
            ->andWhere('s.snapshotDate >= :dateFrom')
            ->andWhere('s.snapshotDate <= :dateTo')
            ->setParameter('companyId', $companyId)
            ->setParameter('dateFrom', $dateFrom)
            ->setParameter('dateTo', $dateTo);

        if ($marketplace !== null) {
            $qb->andWhere('s.marketplace = :marketplace')
                ->setParameter('marketplace', $marketplace);
        }

        return $qb->getQuery()->getResult();
    }

    public function findOneByUniqueKey(
        string $companyId,
        string $listingId,
        \DateTimeImmutable $date,
    ): ?ListingDailySnapshot {
        return $this->findOneBy([
            'companyId' => $companyId,
            'listingId' => $listingId,
            'snapshotDate' => $date,
        ]);
    }

    /**
     * @return array{items: ListingDailySnapshot[], total: int}
     */
    public function findPaginated(
        string $companyId,
        ?string $marketplace,
        ?\DateTimeImmutable $dateFrom,
        ?\DateTimeImmutable $dateTo,
        ?string $listingId,
        int $page,
        int $perPage,
    ): array {
        $qb = $this->createQueryBuilder('s')
            ->where('s.companyId = :companyId')
            ->setParameter('companyId', $companyId);

        if ($marketplace !== null) {
            $qb->andWhere('s.marketplace = :marketplace')
                ->setParameter('marketplace', $marketplace);
        }

        if ($dateFrom !== null) {
            $qb->andWhere('s.snapshotDate >= :dateFrom')
                ->setParameter('dateFrom', $dateFrom);
        }

        if ($dateTo !== null) {
            $qb->andWhere('s.snapshotDate <= :dateTo')
                ->setParameter('dateTo', $dateTo);
        }

        if ($listingId !== null) {
            $qb->andWhere('s.listingId = :listingId')
                ->setParameter('listingId', $listingId);
        }

        $countQb = clone $qb;
        $total = (int) $countQb->select('COUNT(s.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $items = $qb
            ->orderBy('s.snapshotDate', 'DESC')
            ->setMaxResults($perPage)
            ->setFirstResult(($page - 1) * $perPage)
            ->getQuery()
            ->getResult();

        return ['items' => $items, 'total' => $total];
    }

    public function findById(
        string $id,
        string $companyId,
    ): ?ListingDailySnapshot {
        return $this->findOneBy([
            'id' => $id,
            'companyId' => $companyId,
        ]);
    }
}
