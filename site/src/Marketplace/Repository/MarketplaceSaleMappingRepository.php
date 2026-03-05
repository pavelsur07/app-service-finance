<?php

declare(strict_types=1);

namespace App\Marketplace\Repository;

use App\Company\Entity\Company;
use App\Marketplace\Entity\MarketplaceSaleMapping;
use App\Marketplace\Enum\AmountSource;
use App\Marketplace\Enum\MarketplaceType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class MarketplaceSaleMappingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MarketplaceSaleMapping::class);
    }

    public function findByIdAndCompany(string $id, string $companyId): ?MarketplaceSaleMapping
    {
        return $this->createQueryBuilder('m')
            ->leftJoin('m.plCategory', 'pl')->addSelect('pl')
            ->leftJoin('m.projectDirection', 'pd')->addSelect('pd')
            ->andWhere('m.id = :id')
            ->andWhere('IDENTITY(m.company) = :companyId')
            ->setParameter('id', $id)
            ->setParameter('companyId', $companyId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return MarketplaceSaleMapping[]
     */
    public function findByCompanyFiltered(Company $company, ?MarketplaceType $marketplace, string $operationType): array
    {
        $qb = $this->createQueryBuilder('m')
            ->leftJoin('m.plCategory', 'pl')->addSelect('pl')
            ->leftJoin('m.projectDirection', 'pd')->addSelect('pd')
            ->andWhere('m.company = :company')
            ->andWhere('m.operationType = :op')
            ->setParameter('company', $company)
            ->setParameter('op', $operationType);

        if (null !== $marketplace) {
            $qb->andWhere('m.marketplace = :marketplace')
                ->setParameter('marketplace', $marketplace);
        }

        return $qb
            ->orderBy('m.isActive', 'DESC')
            ->addOrderBy('m.marketplace', 'ASC')
            ->addOrderBy('m.amountSource', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Активные правила для конкретного marketplace+operationType, индексированные по amountSource.value.
     *
     * @return array<string, MarketplaceSaleMapping> ['sale_gross' => mapping, ...]
     */
    public function findActiveIndexedByAmountSource(Company $company, MarketplaceType $marketplace, string $operationType): array
    {
        $rows = $this->createQueryBuilder('m')
            ->leftJoin('m.plCategory', 'pl')->addSelect('pl')
            ->leftJoin('m.projectDirection', 'pd')->addSelect('pd')
            ->andWhere('m.company = :company')
            ->andWhere('m.marketplace = :marketplace')
            ->andWhere('m.operationType = :op')
            ->andWhere('m.isActive = true')
            ->setParameter('company', $company)
            ->setParameter('marketplace', $marketplace)
            ->setParameter('op', $operationType)
            ->getQuery()
            ->getResult();

        $indexed = [];
        foreach ($rows as $m) {
            $indexed[$m->getAmountSource()->value] = $m;
        }

        return $indexed;
    }

    /**
     * Инвариант: не более одного активного маппинга на (company, marketplace, operationType, amountSource).
     * При активации текущего — деактивируем остальные активные.
     */
    public function deactivateOtherActive(
        Company $company,
        MarketplaceType $marketplace,
        string $operationType,
        AmountSource $amountSource,
        ?string $excludeId = null
    ): int {
        $qb = $this->getEntityManager()->createQueryBuilder()
            ->update(MarketplaceSaleMapping::class, 'm')
            ->set('m.isActive', ':inactive')
            ->set('m.updatedAt', ':now')
            ->andWhere('m.company = :company')
            ->andWhere('m.marketplace = :marketplace')
            ->andWhere('m.operationType = :op')
            ->andWhere('m.amountSource = :amountSource')
            ->andWhere('m.isActive = true')
            ->setParameter('inactive', false)
            ->setParameter('now', new \DateTimeImmutable())
            ->setParameter('company', $company)
            ->setParameter('marketplace', $marketplace)
            ->setParameter('op', $operationType)
            ->setParameter('amountSource', $amountSource);

        if (null !== $excludeId) {
            $qb->andWhere('m.id <> :excludeId')
               ->setParameter('excludeId', $excludeId);
        }

        return (int) $qb->getQuery()->execute();
    }
}
