<?php

declare(strict_types=1);

namespace App\Marketplace\Wildberries\Repository;

use App\Entity\Company;
use App\Marketplace\Wildberries\Entity\WildberriesReportDetailMapping;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
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
        $qb = $this->createQueryBuilder('m')
            ->andWhere('m.company = :company')
            ->setParameter('company', $company);

        $this->addNullableEqual($qb, 'm.supplierOperName', 'supplierOperName', $supplierOperName);
        $this->addNullableEqual($qb, 'm.docTypeName', 'docTypeName', $docTypeName);
        $this->addNullableEqual($qb, 'm.siteCountry', 'siteCountry', $siteCountry);

        return $qb
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneByKeyAndSourceField(
        Company $company,
        ?string $supplierOperName,
        ?string $docTypeName,
        ?string $siteCountry,
        string $sourceField
    ): ?WildberriesReportDetailMapping {
        $qb = $this->createQueryBuilder('m')
            ->andWhere('m.company = :company')
            ->andWhere('m.sourceField = :sourceField')
            ->setParameter('company', $company)
            ->setParameter('sourceField', $sourceField);

        $this->addNullableEqual($qb, 'm.supplierOperName', 'supplierOperName', $supplierOperName);
        $this->addNullableEqual($qb, 'm.docTypeName', 'docTypeName', $docTypeName);
        $this->addNullableEqual($qb, 'm.siteCountry', 'siteCountry', $siteCountry);

        return $qb
            ->getQuery()
            ->getOneOrNullResult();
    }

    private function addNullableEqual(QueryBuilder $qb, string $field, string $paramName, mixed $value): void
    {
        if ($value === null) {
            $qb->andWhere(sprintf('%s IS NULL', $field));

            return;
        }

        $qb
            ->andWhere(sprintf('%s = :%s', $field, $paramName))
            ->setParameter($paramName, $value);
    }
}
