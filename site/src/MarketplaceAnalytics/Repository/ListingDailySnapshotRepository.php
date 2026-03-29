<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Repository;

use App\Marketplace\Enum\MarketplaceType;
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
        ?MarketplaceType $marketplace = null,
        ?string $listingId = null,
    ): array {
        $qb = $this->createQueryBuilder('s')
            ->where('s.companyId = :companyId')
            ->andWhere('s.snapshotDate >= :dateFrom')
            ->andWhere('s.snapshotDate <= :dateTo')
            ->setParameter('companyId', $companyId)
            ->setParameter('dateFrom', $dateFrom)
            ->setParameter('dateTo', $dateTo)
            ->orderBy('s.snapshotDate', 'ASC');

        if ($marketplace !== null) {
            $qb->andWhere('s.marketplace = :marketplace')
                ->setParameter('marketplace', $marketplace);
        }

        if ($listingId !== null) {
            $qb->andWhere('s.listingId = :listingId')
                ->setParameter('listingId', $listingId);
        }

        return $qb->getQuery()->getResult();
    }

    public function findOneByUniqueKey(
        string $companyId,
        string $listingId,
        \DateTimeImmutable $snapshotDate,
    ): ?ListingDailySnapshot {
        return $this->findOneBy([
            'companyId' => $companyId,
            'listingId' => $listingId,
            'snapshotDate' => $snapshotDate,
        ]);
    }

    /**
     * @return array{items: ListingDailySnapshot[], total: int}
     */
    public function findPaginated(
        string $companyId,
        ?MarketplaceType $marketplace,
        ?\DateTimeImmutable $dateFrom,
        ?\DateTimeImmutable $dateTo,
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

    public function findByIdAndCompany(
        string $id,
        string $companyId,
    ): ?ListingDailySnapshot {
        return $this->findOneBy([
            'id' => $id,
            'companyId' => $companyId,
        ]);
    }
}
