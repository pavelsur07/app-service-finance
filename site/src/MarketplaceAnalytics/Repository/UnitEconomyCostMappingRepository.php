<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Repository;

use App\Marketplace\Enum\MarketplaceType;
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

    /**
     * @return UnitEconomyCostMapping[]
     */
    public function findByCompanyAndMarketplace(
        string $companyId,
        MarketplaceType $marketplace,
    ): array {
        return $this->findBy([
            'companyId' => $companyId,
            'marketplace' => $marketplace,
        ]);
    }

    public function findOneByKey(
        string $companyId,
        MarketplaceType $marketplace,
        string $costCategoryCode,
    ): ?UnitEconomyCostMapping {
        return $this->findOneBy([
            'companyId' => $companyId,
            'marketplace' => $marketplace,
            'costCategoryCode' => $costCategoryCode,
        ]);
    }

    public function findSystemMapping(
        MarketplaceType $marketplace,
        string $costCategoryCode,
    ): ?UnitEconomyCostMapping {
        return $this->findOneBy([
            'marketplace' => $marketplace,
            'costCategoryCode' => $costCategoryCode,
            'isSystem' => true,
        ]);
    }

    public function hasCompanyMappings(
        string $companyId,
        MarketplaceType $marketplace,
    ): bool {
        return (bool) $this->count([
            'companyId' => $companyId,
            'marketplace' => $marketplace,
        ]);
    }

    /**
     * @return array{items: UnitEconomyCostMapping[], total: int}
     */
    public function findPaginated(
        string $companyId,
        ?MarketplaceType $marketplace,
        ?bool $isSystem,
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

        if ($isSystem !== null) {
            $qb->andWhere('m.isSystem = :isSystem')
                ->setParameter('isSystem', $isSystem);
        }

        $countQb = clone $qb;
        $total = (int) $countQb->select('COUNT(m.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $items = $qb
            ->setMaxResults($perPage)
            ->setFirstResult(($page - 1) * $perPage)
            ->getQuery()
            ->getResult();

        return ['items' => $items, 'total' => $total];
    }

    public function findByIdAndCompany(
        string $id,
        string $companyId,
    ): ?UnitEconomyCostMapping {
        return $this->findOneBy([
            'id' => $id,
            'companyId' => $companyId,
        ]);
    }

    public function delete(
        UnitEconomyCostMapping $mapping,
        string $companyId,
    ): void {
        if ($mapping->getCompanyId() !== $companyId) {
            throw new \DomainException('Маппинг не принадлежит компании');
        }

        if ($mapping->isSystem()) {
            throw new \DomainException('Системный маппинг нельзя удалить');
        }

        $this->getEntityManager()->remove($mapping);
    }
}
