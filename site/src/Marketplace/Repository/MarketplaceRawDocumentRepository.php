<?php

namespace App\Marketplace\Repository;

use App\Company\Entity\Company;
use App\Marketplace\Entity\MarketplaceRawDocument;
use App\Marketplace\Enum\MarketplaceType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class MarketplaceRawDocumentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MarketplaceRawDocument::class);
    }

    /**
     * @return MarketplaceRawDocument[]
     */
    public function findByCompany(Company $company, int $limit = 50): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.company = :company')
            ->setParameter('company', $company)
            ->orderBy('d.syncedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function findLatestByType(
        Company $company,
        MarketplaceType $marketplace,
        string $documentType,
    ): ?MarketplaceRawDocument {
        return $this->createQueryBuilder('d')
            ->where('d.company = :company')
            ->andWhere('d.marketplace = :marketplace')
            ->andWhere('d.documentType = :type')
            ->setParameter('company', $company)
            ->setParameter('marketplace', $marketplace)
            ->setParameter('type', $documentType)
            ->orderBy('d.syncedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
