<?php

declare(strict_types=1);

namespace App\Marketplace\Wildberries\Repository;

use App\Entity\Company;
use App\Marketplace\Wildberries\Entity\WildberriesReportDetailMapping;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class WildberriesReportDetailMappingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WildberriesReportDetailMapping::class);
    }

    /**
     * @return WildberriesReportDetailMapping[]
     */
    public function findAllByCompany(Company $company): array
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.company = :company')
            ->setParameter('company', $company)
            ->getQuery()
            ->getResult();
    }

    public function findOneByKey(
        Company $company,
        ?string $supplierOperName,
        ?string $docTypeName,
        ?string $siteCountry
    ): ?WildberriesReportDetailMapping {
        return $this->createQueryBuilder('m')
            ->andWhere('m.company = :company')
            ->andWhere('m.supplierOperName = :supplierOperName')
            ->andWhere('m.docTypeName = :docTypeName')
            ->andWhere('m.siteCountry = :siteCountry')
            ->setParameter('company', $company)
            ->setParameter('supplierOperName', $supplierOperName)
            ->setParameter('docTypeName', $docTypeName)
            ->setParameter('siteCountry', $siteCountry)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
