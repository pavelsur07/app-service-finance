<?php

namespace App\Repository;

use App\Entity\Company;
use App\Entity\ReportApiKey;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ReportApiKey>
 */
class ReportApiKeyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ReportApiKey::class);
    }

    /**
     * @return ReportApiKey[]
     */
    public function findActiveByCompanyAndPrefix(Company $company, string $prefix): array
    {
        return $this->createQueryBuilder('rak')
            ->andWhere('rak.company = :company')
            ->andWhere('rak.keyPrefix = :prefix')
            ->andWhere('rak.isActive = :active')
            ->setParameter('company', $company)
            ->setParameter('prefix', $prefix)
            ->setParameter('active', true)
            ->getQuery()
            ->getResult();
    }

    /**
     * Возвращает активные ключи по префиксу (напр., 'rk_live_').
     *
     * @return ReportApiKey[]
     */
    public function findActiveByPrefix(string $prefix): array
    {
        return $this->createQueryBuilder('k')
            ->andWhere('k.isActive = true')
            ->andWhere('k.keyPrefix = :p')
            ->setParameter('p', $prefix)
            ->getQuery()
            ->getResult();
    }

    public function deactivateAll(Company $company): int
    {
        return $this->createQueryBuilder('rak')
            ->update()
            ->set('rak.isActive', ':inactive')
            ->andWhere('rak.company = :company')
            ->setParameter('inactive', false)
            ->setParameter('company', $company)
            ->getQuery()
            ->execute();
    }
}
