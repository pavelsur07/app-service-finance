<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Repository;

use App\MarketplaceAnalytics\Entity\UnitEconomyCostMapping;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class UnitEconomyCostMappingRepository extends ServiceEntityRepository implements UnitEconomyCostMappingRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UnitEconomyCostMapping::class);
    }

    public function save(UnitEconomyCostMapping $mapping): void
    {
        $this->getEntityManager()->persist($mapping);
    }

    public function findById(
        string $id,
        string $companyId,
    ): ?UnitEconomyCostMapping {
        return $this->findOneBy([
            'id'        => $id,
            'companyId' => $companyId,
        ]);
    }

    public function findOneByCategoryId(
        string $companyId,
        string $marketplace,
        string $costCategoryId,
    ): ?UnitEconomyCostMapping {
        return $this->findOneBy([
            'companyId'      => $companyId,
            'marketplace'    => $marketplace,
            'costCategoryId' => $costCategoryId,
        ]);
    }

    /**
     * @return UnitEconomyCostMapping[]
     */
    public function findByCompanyAndMarketplace(
        string $companyId,
        string $marketplace,
    ): array {
        return $this->findBy([
            'companyId'   => $companyId,
            'marketplace' => $marketplace,
        ]);
    }

    /**
     * @return array{items: UnitEconomyCostMapping[], total: int}
     */
    public function findPaginated(
        string $companyId,
        ?string $marketplace,
        int $page,
        int $perPage,
    ): array {
        $qb = $this->createQueryBuilder('m')
            ->where('m.companyId = :companyId')
            ->setParameter('companyId', $companyId);

        if ($marketplace !== null) {
            $qb->andWhere('m.marketplace = :marketplace')
                ->setParameter('marketplace', $marketplace);
        }

        $total = (int) (clone $qb)
            ->select('COUNT(m.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $items = $qb
            ->orderBy('m.marketplace', 'ASC')
            ->addOrderBy('m.costCategoryName', 'ASC')
            ->setMaxResults($perPage)
            ->setFirstResult(($page - 1) * $perPage)
            ->getQuery()
            ->getResult();

        return ['items' => $items, 'total' => $total];
    }

    public function delete(
        string $id,
        string $companyId,
    ): void {
        $mapping = $this->findById($id, $companyId);

        if ($mapping === null) {
            throw new \DomainException('Маппинг не найден');
        }

        $this->getEntityManager()->remove($mapping);
    }
}
